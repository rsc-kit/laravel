<?php

namespace RscKit;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RscKit\Attributes\Authenticated;
use RscKit\Attributes\Can;
use RscKit\Support\ActionManifest;
use RuntimeException;

class CallableRegistry
{
    /** @var array<string, array{class-string, string}|class-string|Closure> */
    private array $callables = [];

    /** @var array<string, array{authenticated: Authenticated[], can: Can[], middleware: string[]}> */
    private array $attributeCache = [];

    public function __construct(private Container $container) {}

    /**
     * Register a callable by name.
     *
     * @param  array{class-string, string}|class-string|Closure  $callable
     */
    public function register(string $name, array|string|Closure $callable): void
    {
        $this->callables[$name] = $callable;
    }

    /**
     * Auto-discover public methods from classes in the given directory.
     *
     * Discovered names follow the pattern: ClassName.methodName
     * Invokable classes are also registered as: ClassName
     *
     * Explicit registrations take precedence over auto-discovered names.
     */
    public function discoverFrom(string $directory): void
    {
        foreach (ActionManifest::phpFilesUnder($directory) as $file) {
            $className = $this->resolveClassName($file);

            if ($className === null || ! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $shortName = $reflection->getShortName();

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isConstructor()) {
                    continue;
                }

                $name = $method->getName() === '__invoke'
                    ? $shortName
                    : "{$shortName}.{$method->getName()}";

                if (! isset($this->callables[$name])) {
                    $this->callables[$name] = $method->getName() === '__invoke'
                        ? $className
                        : [$className, $method->getName()];
                }
            }
        }
    }

    /**
     * Execute a registered callable by name.
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    public function execute(string $name, array $args): mixed
    {
        if (! isset($this->callables[$name])) {
            throw new RuntimeException("RSC callable not found: \"{$name}\"");
        }

        $callable = $this->callables[$name];

        if ($callable instanceof Closure) {
            return $callable(...$args);
        }

        if (is_string($callable)) {
            $this->authorize($callable, '__invoke');
            $instance = $this->resolveInstance($callable);
            $args = $this->resolveFormRequest($callable, '__invoke', $args);

            return $instance(...$args);
        }

        if (is_array($callable)) {
            [$class, $method] = $callable;
            $this->authorize($class, $method);
            $instance = $this->resolveInstance($class);
            $args = $this->resolveFormRequest($class, $method, $args);

            return $instance->{$method}(...$args);
        }

        throw new RuntimeException("Invalid callable configuration for \"{$name}\"");
    }

    /**
     * Run authorization checks declared via attributes on the class and method.
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    private function authorize(string $class, string $method): void
    {
        $cacheKey = "{$class}::{$method}";

        if (! isset($this->attributeCache[$cacheKey])) {
            $this->attributeCache[$cacheKey] = $this->resolveAttributes($class, $method);
        }

        $attrs = $this->attributeCache[$cacheKey];

        foreach ($attrs['middleware'] as $middleware) {
            $this->runMiddleware($middleware);
        }

        foreach ($attrs['authenticated'] as $attr) {
            if (! Auth::guard($attr->guard)->check()) {
                throw new AuthenticationException('Unauthenticated.', [$attr->guard ?? config('auth.defaults.guard')]);
            }
        }

        foreach ($attrs['can'] as $attr) {
            $arguments = $attr->model !== null ? [$attr->model] : [];
            Gate::authorize($attr->ability, $arguments);
        }
    }

    /**
     * Reflect attributes from both class and method, merging them together.
     *
     * @return array{authenticated: Authenticated[], can: Can[], middleware: string[]}
     */
    private function resolveAttributes(string $class, string $method): array
    {
        $refClass = new ReflectionClass($class);
        $middlewareAttribute = Middleware::class;

        $authenticated = array_map(
            fn (\ReflectionAttribute $a): Authenticated => $a->newInstance(),
            $refClass->getAttributes(Authenticated::class),
        );

        $can = array_map(
            fn (\ReflectionAttribute $a): Can => $a->newInstance(),
            $refClass->getAttributes(Can::class),
        );

        $middleware = array_map(
            fn (\ReflectionAttribute $a): string => $a->newInstance()->middleware,
            $refClass->getAttributes($middlewareAttribute),
        );

        if ($method !== '__invoke' || $refClass->hasMethod($method)) {
            $refMethod = $refClass->getMethod($method);

            $authenticated = array_merge($authenticated, array_map(
                fn (\ReflectionAttribute $a): Authenticated => $a->newInstance(),
                $refMethod->getAttributes(Authenticated::class),
            ));

            $can = array_merge($can, array_map(
                fn (\ReflectionAttribute $a): Can => $a->newInstance(),
                $refMethod->getAttributes(Can::class),
            ));

            $middleware = array_merge($middleware, array_map(
                fn (\ReflectionAttribute $a): string => $a->newInstance()->middleware,
                $refMethod->getAttributes($middlewareAttribute),
            ));
        }

        return [
            'authenticated' => $authenticated,
            'can' => $can,
            'middleware' => array_values(array_unique($middleware)),
        ];
    }

    /**
     * Resolve and run a single middleware through Laravel's Pipeline.
     */
    private function runMiddleware(string $middleware): void
    {
        $request = $this->container->make('request');

        // Resolve middleware alias (e.g. 'auth' → Authenticate::class)
        // through the router so Pipeline gets the actual class, not the helper function.
        $router = $this->container->make(Router::class);
        $resolved = $router->resolveMiddleware([$middleware]);

        (new Pipeline($this->container))
            ->send($request)
            ->through($resolved)
            ->then(fn () => new Response('', 200));
    }

    public function hasCallables(): bool
    {
        return $this->callables !== [];
    }

    /**
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->callables);
    }

    /**
     * If the method's first parameter type-hints a FormRequest, merge the
     * incoming args into the current request and resolve the FormRequest
     * through the container (which triggers validation automatically).
     *
     * @param  array<int|string, mixed>  $args
     * @return array<int, mixed>
     */
    private function resolveFormRequest(string $class, string $method, array $args): array
    {
        $refMethod = new ReflectionMethod($class, $method);
        $params = $refMethod->getParameters();

        if ($params === []) {
            return $args;
        }

        $firstParam = $params[0];
        $type = $firstParam->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $args;
        }

        $typeName = $type->getName();

        if (! is_subclass_of($typeName, FormRequest::class)) {
            return $args;
        }

        // Merge the callable args into the current HTTP request so the
        // FormRequest sees them as input data for validation.
        $httpRequest = $this->container->make('request');
        $data = isset($args[0]) && is_array($args[0]) ? $args[0] : $args;
        $httpRequest->merge($data);

        // Resolve the FormRequest through the container — this runs
        // authorization (authorize()) and validation (rules()) automatically.
        $formRequest = $this->container->make($typeName);

        return [$formRequest];
    }

    /**
     * Resolve the class for this call.
     *
     * Deliberately not cached. This registry is a singleton, and under a
     * persistent runtime — Octane with FrankenPHP, say — a singleton outlives
     * the request that first populated it. A cached instance whose constructor
     * took the Request, the authenticated user, or anything else request-shaped
     * would then be handed to every later request the worker serves, including
     * other people's. Under PHP-FPM the same cache is per-request and saves
     * approximately nothing, so there is no trade here to weigh.
     */
    private function resolveInstance(string $class): object
    {
        return $this->container->make($class);
    }

    /**
     * Resolve a fully-qualified class name from a PHP file path using PSR-4 conventions.
     */
    private function resolveClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        if (preg_match('/namespace\s+([^;]+);/', $contents, $nsMatch)
            && preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            return $nsMatch[1].'\\'.$classMatch[1];
        }

        return null;
    }
}
