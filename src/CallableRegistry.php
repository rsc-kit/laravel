<?php

namespace RscKit;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Routing\Redirector;
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
use Symfony\Component\HttpFoundation\InputBag;

class CallableRegistry
{
    /** @var array<string, array{class-string, string}|class-string|Closure> */
    private array $callables = [];

    /** @var array<string, array{authenticated: Authenticated[], can: Can[], middleware: list<Closure|string>}> */
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
        $this->registerDiscovered(self::discover($directory));
    }

    /**
     * Register what discovery found - freshly, or from the cached map.
     *
     * Explicit registrations still take precedence.
     *
     * @param  array<string, array{class-string, string}|class-string>  $discovered
     */
    public function registerDiscovered(array $discovered): void
    {
        foreach ($discovered as $name => $callable) {
            if (! isset($this->callables[$name])) {
                $this->callables[$name] = $callable;
            }
        }
    }

    /**
     * What a directory offers, by name, without registering any of it.
     *
     * The walk, a read and a parse of every file, and a reflection of every
     * class - which is why `rsc:cache` writes the answer down for production.
     *
     * @return array<string, array{class-string, string}|class-string>
     */
    public static function discover(string $directory): array
    {
        $discovered = [];

        foreach (ActionManifest::phpFilesUnder($directory) as $file) {
            $className = ActionManifest::classIn($file);

            if ($className === null || ! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $shortName = $reflection->getShortName();

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Magic methods are PHP's, not the app's: __call would take any name
                // and any arguments from a browser, __toString and __destruct
                // are not actions. __invoke is the one that is.
                if ($method->isStatic() || $method->isConstructor() || (str_starts_with($method->getName(), '__') && $method->getName() !== '__invoke')) {
                    continue;
                }

                $name = $method->getName() === '__invoke'
                    ? $shortName
                    : "{$shortName}.{$method->getName()}";

                if (! isset($discovered[$name])) {
                    $discovered[$name] = $method->getName() === '__invoke'
                        ? $className
                        : [$className, $method->getName()];
                }
            }
        }

        return $discovered;
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
     * @return array{authenticated: Authenticated[], can: Can[], middleware: list<Closure|string>}
     */
    private function resolveAttributes(string $class, string $method): array
    {
        $refClass = new ReflectionClass($class);
        $middlewareAttribute = Middleware::class;

        // The class and every parent. PHP's getAttributes() reads one class
        // only, so #[Authenticated] on an abstract AdminAction guarded nothing
        // that extended it - and the methods it guarded are inherited, which
        // is how they were discovered in the first place.
        $lineage = [];

        for ($c = $refClass; $c !== false; $c = $c->getParentClass()) {
            $lineage[] = $c;
        }

        $fromLineage = fn (string $attribute): array => array_merge(
            ...array_map(fn (ReflectionClass $c): array => $c->getAttributes($attribute), $lineage),
        );

        $authenticated = array_map(
            fn (\ReflectionAttribute $a): Authenticated => $a->newInstance(),
            $fromLineage(Authenticated::class),
        );

        $can = array_map(
            fn (\ReflectionAttribute $a): Can => $a->newInstance(),
            $fromLineage(Can::class),
        );

        // Closure|string, as Laravel's attribute declares it: an inline
        // middleware is as much a guard as a named one, and typing this as
        // string turned one into a TypeError on every call it guarded.
        $middleware = array_map(
            fn (\ReflectionAttribute $a): Closure|string => $a->newInstance()->middleware,
            $fromLineage($middlewareAttribute),
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
                fn (\ReflectionAttribute $a): Closure|string => $a->newInstance()->middleware,
                $refMethod->getAttributes($middlewareAttribute),
            ));
        }

        // Named middleware once each - a class and its parent both saying
        // 'auth' is one guard. A closure is its own; array_unique would have
        // to turn it into a string to compare it, and cannot.
        $named = array_unique(array_filter($middleware, 'is_string'));

        return [
            'authenticated' => $authenticated,
            'can' => $can,
            'middleware' => array_values(array_filter(
                $middleware,
                fn (Closure|string $m, int $i) => ! is_string($m) || isset($named[$i]),
                ARRAY_FILTER_USE_BOTH,
            )),
        ];
    }

    /**
     * Resolve and run a single middleware through Laravel's Pipeline.
     */
    private function runMiddleware(Closure|string $middleware): void
    {
        $request = $this->container->make('request');

        // Resolve middleware alias (e.g. 'auth' → Authenticate::class)
        // through the router so Pipeline gets the actual class, not the helper
        // function. A closure is already the thing to run.
        $resolved = $middleware instanceof Closure
            ? [$middleware]
            : $this->container->make(Router::class)->resolveMiddleware([$middleware]);

        RouteMiddleware::through($this->container, $request, $resolved);
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
     * If the method's first parameter type-hints a FormRequest, build one
     * from this call's args and validate it, as Laravel would on a route.
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

        $data = isset($args[0]) && is_array($args[0]) ? $args[0] : $args;

        // A request of its own, carrying this call's arguments and nothing
        // else. The request the endpoint received is the host call's: its
        // body is the envelope, so all() answered with "function", "args"
        // and "calls" beside the form's fields, and merging into it meant a
        // batch's second call validated with whatever the first one sent.
        // The session, the user and the route come across with the copy.
        $callRequest = $this->container->make('request')->duplicate([], $data);
        $callRequest->setJson(new InputBag($data));

        // Built and filled here rather than made by the container, which
        // would fill it from the shared request and validate that before
        // this one could be handed over. The rest is what Laravel does to a
        // form request it resolves: authorize(), then rules().
        $formRequest = FormRequest::createFrom($callRequest, $this->container->build($typeName));
        $formRequest->setContainer($this->container)->setRedirector($this->container->make(Redirector::class));
        $formRequest->validateResolved();

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
}
