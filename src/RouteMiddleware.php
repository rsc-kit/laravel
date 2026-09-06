<?php

namespace RscKit;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Response;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;

/**
 * Runs a route's middleware, named by a route.ts in the app tree.
 *
 * This is what route.php's middleware() and can() become once the engine owns
 * the route table. The names are ordinary Laravel middleware — aliases, groups
 * or class names — resolved and run through the real pipeline against the real
 * request, so `auth`, `verified`, `throttle:60,1` and `can:update,post` behave
 * exactly as they do on any other route.
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
     *
     * @throws AuthenticationException
     * @throws RscRedirectException
     */
    public function run(array $names): bool
    {
        if ($names === []) {
            return true;
        }

        $router = $this->container->make(Router::class);
        $request = $this->container->make('request');

        // Resolved through the router so an alias becomes the class it stands
        // for and a group becomes its members. Passing the alias itself to the
        // Pipeline gets a string it cannot instantiate.
        $resolved = $router->resolveMiddleware($names);

        try {
            (new Pipeline($this->container))
                ->send($request)
                ->through($resolved)
                ->then(fn () => new Response('', 200));
        } catch (AuthenticationException $e) {
            // Laravel's own behaviour, which its exception handler supplies on
            // an ordinary route and nothing supplies here: send them to log in
            // if there is somewhere to send them. Without this an unauthenticated
            // visitor gets a failed render rather than the login page.
            $to = $this->loginUrl($e);

            if ($to !== null) {
                throw new RscRedirectException($to);
            }

            throw $e;
        }

        return true;
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
