<?php

namespace RscKit;

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use RscKit\Console\DevCommand;
use RscKit\Console\InstallRuntimeCommand;
use RscKit\Console\RscActionManifestCommand;
use RscKit\Console\RscBuildCommand;
use RscKit\Console\RscExportCommand;
use RscKit\Console\RscPagesCommand;
use RscKit\Console\RscRouteManifestCommand;
use RscKit\Console\ServeCommand;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;

class RscKitServiceProvider extends ServiceProvider
{
    /**
     * Get the CSP nonce for inline script tags.
     * Prioritises spatie/laravel-csp's nonce (works with any generator),
     * then falls back to Vite's nonce (set via Vite::useCspNonce()).
     */
    public static function cspNonce(): ?string
    {
        // spatie/laravel-csp binds the nonce in the container
        try {
            $nonce = app('csp-nonce');
            if ($nonce) {
                return $nonce;
            }
        } catch (\Throwable) {
            // not bound
        }

        return Vite::cspNonce();
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rsc.php', 'rsc');

        $this->app->singleton(RuntimeBridge::class);

        // Scoped, not singleton: what an action invalidated belongs to the
        // request that ran it. Under a persistent runtime a singleton would
        // carry one request's marks into the next.
        $this->app->scoped(Revalidation::class);

        $this->app->singleton(CallableRegistry::class, function ($app) {
            $registry = new CallableRegistry($app);

            $directory = app_path('Rsc');

            if (is_dir($directory)) {
                $registry->discoverFrom($directory);
            }

            $actionsDir = app_path('Rsc/Actions');

            if (is_dir($actionsDir)) {
                $registry->discoverFrom($actionsDir);
            }

            // The reserved name the engine asks route middleware on. Registered
            // here rather than discovered, because it is the engine's own
            // question and not one of the application's functions.
            $registry->register(
                RouteMiddleware::FUNCTION,
                fn (array $names = []) => (new RouteMiddleware($app))->run($names),
            );

            return $registry;
        });

        // Scoped rather than singleton, for the same reason Revalidation is:
        // it holds a per-request Revalidation, and under a persistent runtime
        // a singleton would carry one call's marks into the next.
        $this->app->scoped(HostCallDispatcher::class, function ($app) {
            return new HostCallDispatcher(
                $app->make(CallableRegistry::class),
                $app->make(Revalidation::class),
                (string) config('rsc.host_call_secret'),
            );
        });
    }

    public function boot(): void
    {
        if (config('rsc.enabled')) {
            // Auto-register RscMiddleware in the web middleware group
            // so it runs on all RSC page routes (sets Vary, Cache-Control).
            $this->app['router']->pushMiddlewareToGroup('web', RscMiddleware::class);

            Route::post('/_rsc/action', RscActionController::class)
                ->middleware('web');

            $this->registerHostCallEndpoint();

            // Read rather than walked: the build already found the route tree
            // and wrote it down. Routing therefore needs a build — which was
            // already true for the bundle, and is why a missing manifest names
            // the command rather than registering nothing.
            //
            // Guarded on the manifest rather than on a source directory: the
            // manifest is what registration actually reads, and where the
            // source lives is now the build's business, not this host's.
            if (RouteManifest::exists()) {
                (new PageRouteRegistrar($this->app['router']))
                    ->register(RouteManifest::forApp()->pages());
            }
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rsc.php' => config_path('rsc.php'),
            ], 'rsc-config');

            $this->commands([
                DevCommand::class,
                InstallRuntimeCommand::class,
                ServeCommand::class,
                RscActionManifestCommand::class,
                RscBuildCommand::class,
                RscExportCommand::class,
                RscPagesCommand::class,
                RscRouteManifestCommand::class,
            ]);
        }
    }

    /**
     * The HTTP endpoint a renderer calls back into.
     *
     * Registered only when a secret is configured. Silence rather than an
     * exception, because this is additive: an application that has not opted
     * in is not misconfigured, it simply still uses the socket.
     *
     * On the 'web' group, so the visitor's forwarded cookie starts a session
     * and a function reading auth()->user() finds the person the page is being
     * rendered for. Without it the call runs as nobody and every guard fails
     * open or closed for the wrong reason.
     */
    private function registerHostCallEndpoint(): void
    {
        if (! config('rsc.host_call_secret')) {
            return;
        }

        // The 'web' group without CSRF verification, spelled out rather than
        // named.
        //
        // The session parts are needed: the renderer forwards the visitor's
        // cookie, EncryptCookies decrypts it, StartSession binds their session
        // to the request, and a function asking auth()->user() finds the
        // person the page is being rendered for. Without them every call runs
        // as nobody.
        //
        // CSRF verification is not, and including it makes the endpoint answer
        // 419 to every call. It protects a browser from being tricked into
        // posting with the user's cookies; the caller here is a renderer
        // holding a shared secret, which a browser cannot be tricked into
        // sending. Asking applications to add an exception in bootstrap/app.php
        // would work and would be one more thing to get wrong.
        //
        // AddQueuedCookiesToResponse is what lets a call log someone in: a
        // cookie queued during it reaches this response, and the renderer puts
        // it on the page's.
        Route::post(config('rsc.host_call_path'), HostCallController::class)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                SubstituteBindings::class,
            ]);
    }
}
