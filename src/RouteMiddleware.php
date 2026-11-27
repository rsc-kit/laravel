<?php

namespace RscKit;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Runs a route's middleware, named by a middleware.ts in the app tree.
 *
 * This is what route.php's middleware() and can() become once the engine owns
 * the route table. The names are ordinary Laravel middleware — aliases, groups
 * or class names — resolved and run through the real pipeline against the
 * page's request, so `auth`, `verified`, `throttle:60,1`, `signed` and
 * `can:update,post` behave exactly as they do on any other route.
 *
 * The page's request, not the call's: the call is a POST from the renderer to
 * /__rsc/host-call, and run against that, `signed` checked the endpoint's url
 * and refused every link, while `can:update,post` had no {post} to bind. The
 * engine sends the page beside the names - its url and its params - and the
 * middleware run against a request for it, with the call's session, user
 * and visitor address.
 *
 * Returning true is the only way to allow. Everything else throws, and the
 * engine treats anything that is not a literal true as a refusal — so a
 * middleware that aborts, redirects or simply errors keeps the page from
 * rendering rather than being read as silence.
 */
class RouteMiddleware
{
    /** The reserved host-function name the engine asks on. */
    public const FUNCTION = '__rsc.middleware';

    public function __construct(private Container $container) {}

    /**
     * @param  list<string>  $names  middleware for this route, outermost first
     * @param  array{url?: mixed, params?: mixed}|null  $page  the page they are about; null from an engine before 0.30.50
     *
     * @throws AuthenticationException
     * @throws HttpResponseException a redirect
     */
    public function run(array $names, ?array $page = null): bool
    {
        if ($names === []) {
            return true;
        }

        // Resolved through the router so an alias becomes the class it stands
        // for and a group becomes its members. Passing the alias itself to the
        // Pipeline gets a string it cannot instantiate.
        $resolved = $this->container->make(Router::class)->resolveMiddleware($names);

        try {
            $this->throughPage($resolved, $page);
        } catch (AuthenticationException $e) {
            // Laravel's own behaviour, which its exception handler supplies on
            // an ordinary route and nothing supplies here: send them to log in
            // if there is somewhere to send them. Without this an unauthenticated
            // visitor gets a failed render rather than the login page.
            $to = $this->loginUrl($e);

            if ($to !== null) {
                throw new HttpResponseException(new RedirectResponse($to));
            }

            throw $e;
        } catch (RouteNotFoundException $e) {
            // Laravel's `auth` asks for route('login') before it refuses, so
            // an app with no login route fails here, with a message about a
            // route rather than about the guest it was turning away.
            if (! str_contains($e->getMessage(), '[login]')) {
                throw $e;
            }

            throw new RouteNotFoundException(
                'Route [login] not defined. `auth` sends a guest to route(\'login\'): add a login page '
                .'(resources/js/app/login/page.tsx is named login), or say where in bootstrap/app.php '
                .'with $middleware->redirectGuestsTo(\'/login\').',
                previous: $e,
            );
        }

        return true;
    }

    /**
     * Run resolved middleware against a request for the page, or the call's
     * own when there is no page.
     *
     * The page's request is THE request while they run, so a policy or a
     * limiter reaching for request() finds the page too.
     *
     * @param  array<int, mixed>  $resolved
     * @param  array{url?: mixed, params?: mixed}|null  $page
     *
     * @throws HttpResponseException a redirect
     * @throws HttpException
     */
    public function throughPage(array $resolved, ?array $page): void
    {
        $this->throughRequest($resolved, $this->requestFor($page) ?? $this->container->make('request'));
    }

    /**
     * Run resolved middleware against this request, THE request while they run.
     *
     * @param  array<int, mixed>  $resolved
     *
     * @throws HttpResponseException a redirect
     * @throws HttpException
     */
    public function throughRequest(array $resolved, Request $request): void
    {
        $call = $this->container->make('request');

        $this->swapRequest($request);

        try {
            self::through($this->container, $request, $resolved);
        } finally {
            $this->swapRequest($call);
        }
    }

    /**
     * A request for the page, carrying the call's visitor, or null when there
     * is no page - see pageRequest().
     *
     * Given what an action runs - `Class@method`, or a closure - the page's
     * route runs it, and its params are left as the strings they were: a
     * SubstituteBindings in the action's middleware binds them, explicitly
     * and implicitly from the method's own signature, where Laravel's
     * priority puts it - after `auth`, so a guest is turned away before
     * anyone looks a record up, and before `can`, so the policy is handed
     * the model.
     *
     * Given the fields a form posted, it is that post: `throttle:login`
     * keys on the email, and read from a GET of the page it found none, so
     * every account behind one address shared a single bucket.
     *
     * @param  array{url?: mixed, params?: mixed}|null  $page
     * @param  array<array-key, mixed>  $fields
     */
    public function requestFor(?array $page, string|Closure|null $uses = null, array $fields = []): ?Request
    {
        if (! is_string($page['url'] ?? null)) {
            return null;
        }

        return $this->pageRequest($this->container->make('request'), $this->container->make(Router::class), $page, $uses, $fields);
    }

    /**
     * A request for the page, carrying the call's visitor.
     *
     * The url the visitor asked for, as a GET, under the session, user and
     * address the call arrived with; a route matching it with the params the
     * engine matched, explicit bindings applied - `Route::model('invoice',
     * Invoice::class)` - so `can:update,invoice` is handed the invoice. There
     * is no controller to read an implicit binding's type from, so a param
     * nothing binds stays the string it was, and a policy expecting a model
     * refuses it.
     *
     * @param  array{url: string, params?: mixed}  $page
     * @param  array<array-key, mixed>  $fields
     */
    private function pageRequest(Request $call, Router $router, array $page, string|Closure|null $uses = null, array $fields = []): Request
    {
        $server = $call->server->all();

        // Nothing of the call itself: its secret, its body, a forwarded host
        // that would put the page somewhere it is not.
        foreach (array_keys($server) as $key) {
            if (str_starts_with($key, 'HTTP_X_RSC_') || str_starts_with($key, 'HTTP_X_FORWARDED_') || str_starts_with($key, 'CONTENT_') || $key === 'HTTP_CONTENT_TYPE' || $key === 'HTTP_CONTENT_LENGTH') {
                unset($server[$key]);
            }
        }

        $request = Request::create($page['url'], $fields === [] ? 'GET' : 'POST', $fields, $call->cookies->all(), [], $server);

        if ($call->hasSession()) {
            $request->setLaravelSession($call->session());
        }

        $request->setUserResolver($call->getUserResolver());

        $route = new Route(['GET', 'HEAD'], $request->path(), $uses === null ? fn () => null : ['uses' => $uses]);
        $route->setRouter($router)->setContainer($this->container);
        $route->bind($request);

        foreach (is_array($page['params'] ?? null) ? $page['params'] : [] as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $route->setParameter($name, $value);
            }
        }

        $request->setRouteResolver(fn () => $route);

        if ($uses === null) {
            $router->substituteBindings($route);
        }

        return $request;
    }

    /** Make this the container's request, and the facade's. */
    private function swapRequest(Request $request): void
    {
        if ($this->container->make('request') === $request) {
            return;
        }

        $this->container->instance('request', $request);
        Facade::clearResolvedInstance('request');
    }

    /**
     * Run middleware and return only if every one of them passed the request on.
     *
     * A middleware refuses in one of two ways. It can throw, as `auth` and
     * `can` do. Or it can answer: `verified`, `guest` and `password.confirm`
     * return a redirect, and a hand-written guard may return a 403. A
     * pipeline hands either answer back as its result, and reading only the
     * exceptions let every one of those through - an unverified visitor saw
     * the verified-only page.
     *
     * So allowing means the pipeline reached its end. An answer is a refusal:
     * a redirect goes to where it points, and anything else fails with the
     * answer's own status, or 403 when that status would read as success.
     *
     * @param  array<int, mixed>  $middleware  resolved
     *
     * @throws HttpResponseException a redirect
     * @throws HttpException
     */
    public static function through(Container $container, mixed $request, array $middleware): void
    {
        $passed = false;

        $answer = (new Pipeline($container))
            ->send($request)
            ->through($middleware)
            ->then(function () use (&$passed) {
                $passed = true;

                return new Response('', 200);
            });

        if ($passed) {
            return;
        }

        if ($answer instanceof SymfonyResponse && $answer->isRedirect() && $answer->headers->has('Location')) {
            throw new HttpResponseException($answer);
        }

        $status = $answer instanceof SymfonyResponse ? $answer->getStatusCode() : 403;

        throw new HttpException($status >= 400 ? $status : 403, 'Refused by middleware.');
    }

    /**
     * Where an unauthenticated visitor should go, if anywhere.
     *
     * The exception carries the guards' own redirect when middleware set one.
     * Otherwise a conventional `login` route, and null when the application
     * has neither — an API-only app should get a 401, not a redirect to a page
     * that does not exist.
     */
    private function loginUrl(AuthenticationException $e): ?string
    {
        // Every step of this is best-effort, and a failure means "nowhere to
        // send them" rather than a failed render. The exception consults a
        // STATIC callback that an application — or another test in the same
        // process — may have installed, and that callback commonly resolves a
        // named route, which throws when the route is absent.
        try {
            $request = $this->container->make('request');
            $configured = $e->redirectTo($request);

            if (is_string($configured) && $configured !== '') {
                return $configured;
            }

            $routes = $this->container->make(Router::class)->getRoutes();

            // Refreshed before asking. The name lookup is built lazily and is
            // empty until something forces it, so a bare check reports that
            // the application has no named routes at all — and every
            // unauthenticated visitor gets a failed render instead of the
            // login page.
            $routes->refreshNameLookups();

            if (! $routes->hasNamedRoute('login')) {
                return null;
            }

            // Rooted, because a bare "login" is relative to whatever path the
            // visitor was on when they were turned away.
            return '/'.ltrim($routes->getByName('login')->uri(), '/');
        } catch (\Throwable) {
            return null;
        }
    }
}
