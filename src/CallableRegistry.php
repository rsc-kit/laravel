<?php

namespace RscKit;

use Closure;
use Error;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Routing\Attributes\Controllers\WithoutMiddleware;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Redirector;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use LogicException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use RscKit\Build\WatchedAttributes;
use RscKit\Http\HostCallDispatcher;
use RscKit\Support\ActionManifest;
use RscKit\Support\Injected;
use RuntimeException;
use Symfony\Component\HttpFoundation\InputBag;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;
use Throwable;

class CallableRegistry
{
    /** What a guard is called, lower-cased as PHP compares it. */
    private const GUARDS = ['middleware', 'authorize', 'authenticated', 'can'];

    /** @var array<string, array{class-string, string}|class-string|Closure> */
    private array $callables = [];

    /** @var array<string, array{middleware: list<Closure|string>, without: list<string>, names: list<string>}> */
    private array $attributeCache = [];

    /** The page the call running now was posted from, as a request for it - see execute(). */
    private ?Request $page = null;

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
    public function execute(string $name, array $args, ?array $validate = null): mixed
    {
        if (! isset($this->callables[$name])) {
            throw new RuntimeException("RSC callable not found: \"{$name}\"");
        }

        $callable = $this->callables[$name];

        // The page it was posted from, as a request whose route runs this
        // callable - see RouteMiddleware::requestFor(). One for the guards,
        // the form and the models alike, so the post a policy was handed is
        // the post the method is.
        $previous = $this->page;
        $this->page = (new RouteMiddleware($this->container))->requestFor($this->postedFrom(), self::uses($callable));

        try {
            try {
                $result = $this->run($callable, $name, $args, $validate);
            } catch (HttpResponseException $e) {
                // Laravel's way to end a request from deep inside it: the
                // response is the answer, as if the callable had returned it.
                $result = $e->getResponse();
            }

            return $this->answer($name, $result);
        } finally {
            $this->page = $previous;
        }
    }

    /**
     * What a controller's return would have meant, for a callable that
     * returned one.
     *
     * redirect(), to_route() and back() are performed as a guard's redirect
     * is, and a Responsable - Fortify's LoginResponse - is asked what it is
     * first. Left alone, a RedirectResponse was serialised as an object of
     * headers and the visitor stayed where they were, with nothing said.
     *
     * @throws HttpResponseException a redirect
     */
    private function answer(string $name, mixed $result): mixed
    {
        if ($result instanceof Responsable) {
            $result = $result->toResponse($this->container->make('request'));
        }

        if (! $result instanceof SymfonyResponse) {
            return $result;
        }

        // A response an exception was rendered into is that exception. Laravel's
        // routing pipeline catches what its pipes throw and renders it - which
        // is how Fortify's login answers a wrong password: back(), the errors
        // flashed. Performed, that redirect is a form that reloads in silence;
        // rethrown, it is the 422 with its fields that it was.
        if (isset($result->exception) && $result->exception instanceof Throwable) {
            throw $result->exception;
        }

        if ($result->isRedirect() && $result->headers->has('Location')) {
            throw new HttpResponseException($result);
        }

        if ($result instanceof JsonResponse) {
            return $result->getData(true);
        }

        throw new LogicException(sprintf(
            '[%s] answered with a %s. A callable returns data, or a redirect; a page renders the rest.',
            $name,
            $result::class,
        ));
    }

    /**
     * What a route that runs this callable says it runs.
     *
     * @param  array{class-string, string}|class-string|Closure  $callable
     */
    private static function uses(array|string|Closure $callable): string|Closure
    {
        return match (true) {
            $callable instanceof Closure => $callable,
            is_array($callable) => $callable[0].'@'.$callable[1],
            default => $callable.'@__invoke',
        };
    }

    /**
     * @param  array{class-string, string}|class-string|Closure  $callable
     *
     * @throws AuthenticationException
     * @throws AuthorizationException
     */
    private function run(array|string|Closure $callable, string $name, array $args, ?array $validate): mixed
    {
        // Checked, not run - Laravel's Precognition. Everything up to the
        // call happens as it would: the attributes' guards, then the form
        // request's authorize() and rules, filtered to the fields named.
        // Then nothing: a null result is the fields passing, and a refusal
        // is the ValidationException it always was.
        if ($validate !== null) {
            if (! self::validatesEarly($callable)) {
                throw new RuntimeException("\"{$name}\" takes no form request, so it cannot be checked without running.");
            }

            if (is_string($callable)) {
                $this->authorize($callable, '__invoke');
                $this->resolveFormRequest(new ReflectionMethod($callable, '__invoke'), $args, $validate);
            } elseif (is_array($callable)) {
                $this->authorize($callable[0], $callable[1]);
                $this->resolveFormRequest(new ReflectionMethod($callable[0], $callable[1]), $args, $validate);
            } else {
                $this->resolveFormRequest(new ReflectionFunction($callable), $args, $validate);
            }

            return null;
        }

        if ($callable instanceof Closure) {
            return $callable(...$this->arguments(new ReflectionFunction($callable), $args));
        }

        if (is_string($callable)) {
            $this->authorize($callable, '__invoke');
            $instance = $this->resolveInstance($callable);
            $args = $this->arguments(new ReflectionMethod($callable, '__invoke'), $args);

            return $instance(...$args);
        }

        if (is_array($callable)) {
            [$class, $method] = $callable;
            $this->authorize($class, $method);
            $instance = $this->resolveInstance($class);
            $args = $this->arguments(new ReflectionMethod($class, $method), $args);

            return $instance->{$method}(...$args);
        }

        throw new RuntimeException("Invalid callable configuration for \"{$name}\"");
    }

    /**
     * Whether a callable can be checked without running: the first argument it
     * takes is a form request, which is where its validation is. Validation
     * written inside the method runs only by running it.
     *
     * @param  array{class-string, string}|class-string|Closure  $callable
     */
    public static function validatesEarly(array|string|Closure $callable): bool
    {
        try {
            $reflection = match (true) {
                $callable instanceof Closure => new ReflectionFunction($callable),
                is_string($callable) => new ReflectionMethod($callable, '__invoke'),
                default => new ReflectionMethod($callable[0], $callable[1]),
            };
        } catch (\ReflectionException) {
            return false;
        }

        $type = (Injected::leaves($reflection)[0] ?? null)?->getType();

        return $type instanceof ReflectionNamedType
            && ! $type->isBuiltin()
            && is_subclass_of($type->getName(), FormRequest::class);
    }

    /**
     * The names of the registered callables that can be checked without
     * running - see validatesEarly().
     *
     * @param  array<string, array{class-string, string}|class-string|Closure>  $callables
     * @return list<string>
     */
    public static function validating(array $callables): array
    {
        $names = array_keys(array_filter($callables, self::validatesEarly(...)));

        sort($names);

        return $names;
    }

    /**
     * What a callable asks of a visitor before it runs, or null when nothing:
     * a guard on its class or method, or a form request, which is what a
     * visitor typed. For the build, which has nobody to ask.
     */
    public function asksFor(string $name): ?string
    {
        $callable = $this->callables[$name] ?? null;

        if ($callable === null) {
            return null;
        }

        if (self::validatesEarly($callable)) {
            return 'a form';
        }

        if ($callable instanceof Closure) {
            return null;
        }

        [$class, $method] = is_array($callable) ? $callable : [$callable, '__invoke'];
        $attrs = $this->resolveAttributes($class, $method);

        return $attrs['names'] === [] ? null : "#[{$attrs['names'][0]}]";
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

        $this->runMiddleware($attrs['middleware'], $attrs['without']);
    }

    /**
     * The guards on a class, every parent of it, and the method.
     *
     * PHP's getAttributes() reads one class only, so a guard on an abstract
     * AdminAction guarded nothing that extended it - and the methods it
     * guarded are inherited, which is how they were discovered in the first
     * place. It also matches a name exactly unless told otherwise, and
     * Laravel's #[Authorize] extends #[Middleware]: read by name, it was no
     * guard at all and the method ran for anyone.
     *
     * Laravel's own are read as its router reads them on a controller: the
     * parent's first, then the class's, then the method's, with `only` and
     * `except`.
     *
     * @return array{middleware: list<Closure|string>, without: list<string>, names: list<string>}
     *
     * @throws LogicException
     */
    private function resolveAttributes(string $class, string $method): array
    {
        $sources = self::guardSources($class, $method);

        if ($dead = self::deadGuard($sources)) {
            throw new LogicException($dead);
        }

        /**
         * @template T of object
         *
         * @param  class-string<T>  $attribute
         * @return list<T>
         */
        $read = fn (string $attribute): array => array_map(
            fn (ReflectionAttribute $a): object => $a->newInstance(),
            array_merge(...array_map(
                fn (ReflectionClass|ReflectionMethod $source): array => $source->getAttributes($attribute, ReflectionAttribute::IS_INSTANCEOF),
                $sources,
            )),
        );

        $applies = fn (Middleware|WithoutMiddleware $a): bool => ! Route::methodExcludedByOptions(
            $method, ['only' => $a->only, 'except' => $a->except],
        );

        $guards = array_values(array_filter($read(Middleware::class), $applies));

        // Named middleware once each - a class and its parent both saying
        // 'auth' is one guard. A closure is its own; array_unique would have
        // to turn it into a string to compare it, and cannot.
        $middleware = array_map(fn (Middleware $a): Closure|string => $a->middleware, $guards);
        $named = array_unique(array_filter($middleware, 'is_string'));

        return [
            'middleware' => array_values(array_filter(
                $middleware,
                fn (Closure|string $m, int $i) => ! is_string($m) || isset($named[$i]),
                ARRAY_FILTER_USE_BOTH,
            )),
            'without' => array_values(array_map(
                fn (WithoutMiddleware $a): string => $a->middleware,
                array_filter($read(WithoutMiddleware::class), $applies),
            )),
            'names' => array_map(fn (object $a): string => class_basename($a), $guards),
        ];
    }

    /**
     * Where a callable's guards are written, outermost first: the furthest
     * parent, down to the class, then the method.
     *
     * @param  class-string  $class
     * @return list<ReflectionClass<object>|ReflectionMethod>
     */
    private static function guardSources(string $class, string $method): array
    {
        $refClass = new ReflectionClass($class);
        $sources = [];

        for ($c = $refClass; $c !== false; $c = $c->getParentClass()) {
            array_unshift($sources, $c);
        }

        if ($refClass->hasMethod($method)) {
            $sources[] = $refClass->getMethod($method);
        }

        return $sources;
    }

    /**
     * The guard among these that guards nothing, as a sentence, or null.
     *
     * PHP says nothing about an attribute until it is made. One whose `use`
     * was forgotten, or names Illuminate\Routing\Controllers\Middleware,
     * which is no attribute, or is the #[Authenticated] or #[Can] this package
     * had before it read Laravel's, would leave the method open to anyone, so
     * the call is refused instead.
     *
     * @param  list<ReflectionClass<object>|ReflectionMethod>  $sources
     */
    private static function deadGuard(array $sources): ?string
    {
        foreach ($sources as $source) {
            foreach ($source->getAttributes() as $attribute) {
                if (! in_array(strtolower(class_basename($attribute->getName())), self::GUARDS, true)) {
                    continue;
                }

                try {
                    $attribute->newInstance();
                } catch (Error $e) {
                    return sprintf(
                        '%s is guarded by #[%s], which guards nothing: %s. It would be open to anyone. '
                        .'Use Illuminate\Routing\Attributes\Controllers\Middleware, as #[Middleware(\'auth\')], or Authorize.',
                        $source instanceof ReflectionMethod ? "{$source->class}::{$source->name}()" : $source->name,
                        $attribute->getName(),
                        $e->getMessage(),
                    );
                }
            }
        }

        return null;
    }

    /**
     * Every guard that guards nothing, for the build to fail on before a
     * visitor's call does.
     *
     * @param  array<string, array{class-string, string}|class-string|Closure>  $callables
     * @return list<string>
     */
    public static function deadGuards(array $callables): array
    {
        $dead = [];

        foreach ($callables as $callable) {
            if (! $callable instanceof Closure) {
                [$class, $method] = is_array($callable) ? $callable : [$callable, '__invoke'];
                $dead[] = self::deadGuard(self::guardSources($class, $method));
            }
        }

        return array_values(array_unique(array_filter($dead)));
    }

    /**
     * Run a callable's middleware as a route's: an alias made its class, a
     * group its members, sorted by Laravel's priority - so `auth` has chosen
     * the guard before a policy asks who it is - and less what the class or
     * method goes without.
     *
     * @param  list<Closure|string>  $middleware
     * @param  list<string>  $without
     */
    private function runMiddleware(array $middleware, array $without): void
    {
        // The page's params bound as a route's are: Laravel's own middleware,
        // in the place its priority gives it among the guards.
        if ($this->page !== null && $this->page->route()->parameters() !== []) {
            $middleware[] = SubstituteBindings::class;
        }

        if ($middleware === []) {
            return;
        }

        $resolved = $this->container->make(Router::class)->resolveMiddleware($middleware, $without);

        try {
            // Against the page the action was posted from, when the renderer
            // said which: `signed` checks the link the visitor followed to
            // it, not this endpoint's url, and `can:update,post` finds {post}.
            (new RouteMiddleware($this->container))->throughRequest($resolved, $this->page ?? $this->container->make('request'));
        } catch (RouteNotFoundException $e) {
            // `auth` asks for route('login') before it refuses, to have
            // somewhere to send the guest. A call sends nobody anywhere: it
            // answers 401, so an app with no such route still refuses as one.
            if (! str_contains($e->getMessage(), '[login]')) {
                throw $e;
            }

            throw new AuthenticationException;
        }
    }

    /**
     * The page the call running now was posted from, or null - see
     * HostCallController::notePage().
     *
     * @return array{url: string, params: array<string, string>}|null
     */
    private function postedFrom(): ?array
    {
        $attributes = $this->container->bound('request') ? $this->container->make('request')->attributes : null;

        // A build asks as nobody, from no page - and its request notes every
        // attribute read as the call needing a visitor.
        if ($attributes === null || $attributes instanceof WatchedAttributes) {
            return null;
        }

        $page = $attributes->get(HostCallDispatcher::PAGE_ATTRIBUTE);

        return is_array($page) ? $page : null;
    }

    public function hasCallables(): bool
    {
        return $this->callables !== [];
    }

    /**
     * Every callable, by name: what the manifest describes the types of.
     *
     * @return array<string, array{class-string, string}|class-string|Closure>
     */
    public function all(): array
    {
        return $this->callables;
    }

    /**
     * @return array<string>
     */
    public function names(): array
    {
        return array_keys($this->callables);
    }

    /**
     * Everything the callable takes: the call's arguments, its form request
     * validated first, then what the container makes. A refused form makes
     * nothing.
     *
     * @param  array<int, mixed>  $args
     * @return array<int, mixed>
     */
    private function arguments(ReflectionFunctionAbstract $callable, array $args): array
    {
        // A closure has no attributes, so nothing above bound its page.
        if ($callable instanceof ReflectionFunction) {
            $this->runMiddleware([], []);
        }

        return Injected::fill($callable, $this->resolveFormRequest($callable, $args), $this->container, $this->pageParams());
    }

    /**
     * The page's route params, bound once its guards have run: the model a
     * `can:update,post` was handed is the `Post $post` the method takes.
     *
     * @return array<string, mixed>
     */
    private function pageParams(): array
    {
        return $this->page?->route()?->parameters() ?? [];
    }

    /**
     * If the first argument the callable takes is a FormRequest, build one
     * from this call's args and validate it, as Laravel would on a route. A
     * plain Request is built the same way and not validated.
     *
     * What follows the form is passed on, and is the request's route
     * parameters by the method's own names: a panel's update(values, id)
     * reaches update(UpdateProductRequest $request, string $product), and the
     * rules read $this->route('product') - Rule::unique()->ignore() - as they
     * would on PUT /products/{product}.
     *
     * @param  array<int|string, mixed>  $args
     * @return array<int, mixed>
     */
    private function resolveFormRequest(ReflectionFunctionAbstract $callable, array $args, ?array $validate = null): array
    {
        $params = Injected::leaves($callable);

        if ($params === []) {
            return $args;
        }

        $firstParam = $params[0];
        $type = $firstParam->getType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return $args;
        }

        $typeName = $type->getName();
        $form = is_subclass_of($typeName, FormRequest::class);

        // logout(Request $request), as a controller's: the call's own request,
        // the form's fields its input. Never the endpoint's, whose input is
        // the envelope: $request->input('email') answered nothing.
        if (! $form && ! is_a($typeName, Request::class, true)) {
            return $args;
        }

        $data = isset($args[0]) && is_array($args[0]) ? $args[0] : $args;
        $rest = isset($args[0]) && is_array($args[0]) ? array_slice(array_values($args), 1) : [];

        // A request of its own, carrying this call's arguments and nothing
        // else. The request the endpoint received is the host call's: its
        // body is the envelope, so all() answered with "function", "args"
        // and "calls" beside the form's fields, and merging into it meant a
        // batch's second call validated with whatever the first one sent.
        // The session, the user and the route come across with the copy.
        $callRequest = $this->container->make('request')->duplicate([], $data);
        $callRequest->setJson(new InputBag($data));

        // The page's params first, then the method's own arguments by name:
        // posted from /verify-email/{id}/{hash}, EmailVerificationRequest's
        // $this->route('id') is the page's, as on Laravel's own route.
        $page = $this->pageParams();

        if ($rest !== [] || $page !== []) {
            $this->routeTo($callRequest, array_slice($params, 1), $rest, $page);
        }

        if (! $form) {
            return [$typeName === Request::class ? $callRequest : $typeName::createFrom($callRequest), ...$rest];
        }

        // Built and filled here rather than made by the container, which
        // would fill it from the shared request and validate that before
        // this one could be handed over. The rest is what Laravel does to a
        // form request it resolves: authorize(), then rules().
        // A check is a precognitive request, as Laravel's own middleware
        // marks one: the form request filters its rules to the fields named
        // and answers 204 when they pass, and a rules() that asks
        // isPrecognitive() is answered truthfully. No fields named is every
        // rule, as a precognitive request with no Validate-Only header is.
        if ($validate !== null) {
            $callRequest->attributes->set('precognitive', true);
            $callRequest->headers->set('Precognition', 'true');

            if ($validate !== []) {
                $callRequest->headers->set('Precognition-Validate-Only', implode(',', $validate));
            }
        }

        $formRequest = FormRequest::createFrom($callRequest, $this->container->build($typeName));
        $formRequest->setContainer($this->container)->setRedirector($this->container->make(Redirector::class));

        try {
            $formRequest->validateResolved();
        } catch (HttpException $e) {
            // Precognition's way of saying the fields passed: abort(204)
            // from the validator's after hook. Here it is a null result.
            if ($validate !== null && $e->getStatusCode() === 204) {
                return [$formRequest, ...$rest];
            }

            throw $e;
        }

        return [$formRequest, ...$rest];
    }

    /**
     * A route carrying the arguments after the form, named as the method
     * names them, with the app's explicit bindings applied: Route::model()
     * makes $this->route('product') the model, as on its own route.
     *
     * @param  array<int, \ReflectionParameter>  $params
     * @param  array<int, mixed>  $values
     * @param  array<string, string>  $page  the params of the page it was posted from, under the method's own
     */
    private function routeTo(Request $request, array $params, array $values, array $page = []): void
    {
        $router = $this->container->make(Router::class);
        $route = new Route(['POST'], $request->path(), fn () => null);
        $route->setRouter($router)->setContainer($this->container);
        $route->bind($request);

        foreach ($params as $index => $param) {
            if (array_key_exists($index, $values) && is_scalar($values[$index])) {
                $route->setParameter($param->getName(), (string) $values[$index]);
            }
        }

        $request->setRouteResolver(fn () => $route);
        $router->substituteBindings($route);

        // After binding, since they are bound already: a model handed to an
        // explicit binder again is looked up by itself. The method's own win.
        foreach ($page as $name => $value) {
            if (! $route->hasParameter($name)) {
                $route->setParameter($name, $value);
            }
        }
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
