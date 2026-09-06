<?php

namespace RscKit;

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use RscKit\Console\RscActionManifestCommand;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;
use RscKit\Http\RendererProxy;

/**
 * Laravel as the backend of an rsc-kit application.
 *
 * The renderer owns the request: routing, rendering, prerendering and static
 * serving are all its, and the file tree is the route table. What is left here
 * is what only Laravel can answer — the data, the session, and whether a route
 * may render at all.
 *
 * That is two things behind one endpoint. Functions the app's server
 * components call, discovered by reflection through Composer's autoloader; and
 * the middleware a route.ts names, run through Laravel's own pipeline. Both
 * arrive as a POST from the renderer and leave as JSON.
 */
class RscKitServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rsc.php', 'rsc');

        // Scoped, not singleton: what a call invalidated belongs to the request
        // that made it. Under a persistent runtime a singleton would carry one
        // request's marks into the next.
        $this->app->scoped(Revalidation::class);

        $this->app->singleton(CallableRegistry::class, function ($app) {
            $registry = new CallableRegistry($app);

            foreach ([app_path('Rsc'), app_path('Rsc/Actions')] as $directory) {
                if (is_dir($directory)) {
                    $registry->discoverFrom($directory);
                }
            }

            // The reserved name the renderer asks route middleware on.
            // Registered rather than discovered, because it answers the
            // engine's own question and is not one of the app's functions.
            $registry->register(
                RouteMiddleware::FUNCTION,
                fn (array $names = []) => (new RouteMiddleware($app))->run($names),
            );

            return $registry;
        });

        $this->app->scoped(HostCallDispatcher::class, fn ($app) => new HostCallDispatcher(
            $app->make(CallableRegistry::class),
            $app->make(Revalidation::class),
            (string) config('rsc.host_call_secret'),
        ));
    }

    public function boot(): void
    {
        $this->registerHostCallEndpoint();
        $this->registerRendererFallback();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rsc.php' => config_path('rsc.php'),
            ], 'rsc-config');

            $this->commands([RscActionManifestCommand::class]);
        }
    }

    /**
     * The endpoint the renderer calls back into.
     *
     * Registered only when a secret is configured. Silence rather than an
     * exception: an application that has not set one is not misconfigured, it
     * simply has no renderer talking to it yet.
     *
     * The middleware is the 'web' group without CSRF verification, spelled out
     * rather than named. The session parts are needed — the renderer forwards
     * the visitor's cookie, EncryptCookies decrypts it, StartSession binds
     * their session to the request, and a function asking auth()->user() finds
     * the person the page is being rendered for. CSRF verification is not: it
     * protects a browser from being tricked into posting with the user's
     * cookies, and the caller here holds a shared secret, which a browser
     * cannot be tricked into sending. With it, every call answers 419.
     *
     * AddQueuedCookiesToResponse is what lets a call log someone in: a cookie
     * queued during it reaches this response, and the renderer puts it on the
     * page's.
     */
    private function registerHostCallEndpoint(): void
    {
        if (! config('rsc.host_call_secret')) {
            return;
        }

        Route::post(config('rsc.host_call_path'), HostCallController::class)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
            ]);
    }

    /**
     * Everything Laravel does not route goes to the renderer.
     *
     * A fallback, so it is genuinely last: real routes, the host-call endpoint
     * and any package's routes all match first. That is what lets an app parked
     * in ~/Herd work at its own .test domain with no configuration — which is
     * what a Laravel developer expects of a Laravel application.
     *
     * On the 'web' group, so the session and cookies a page reads are the
     * ones the framework already resolved.
     */
    private function registerRendererFallback(): void
    {
        // Registered unconditionally, and the handler decides. A dev server
        // starts and stops long after routes are registered, so a check here
        // would read whether one was running at boot rather than now — and an
        // app booted before `vite dev` would serve 404s until it restarted.
        Route::fallback(RendererProxy::class)->middleware('web');
    }
}
