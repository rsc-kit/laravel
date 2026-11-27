<?php

namespace RscKit;

use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\RouteCollection;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use LogicException;
use RscKit\Console\InstallCommand;
use RscKit\Console\MakeActionCommand;
use RscKit\Console\PruneVersionsCommand;
use RscKit\Console\RscCacheCommand;
use RscKit\Console\RscClearCommand;
use RscKit\Console\RscHostManifestCommand;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;
use RscKit\Http\RendererProxy;
use RscKit\Support\RendererRoutes;

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
 * the middleware a middleware.ts names, run through Laravel's own pipeline. Both
 * arrive as a POST from the renderer and leave as JSON.
 */
class RscKitServiceProvider extends ServiceProvider
{
    /**
     * The engine release this one is built against.
     *
     * The two halves ship separately and cannot depend on each other: one is a
     * composer package, the other is on npm. So the pairing is written down
     * here and checked by rsc:install, rather than left to a sentence in a
     * changelog — a renderer a major behind does not fail at boot, it fails at
     * whichever request first needs the part that changed.
     */
    public const ENGINE_CONSTRAINT = '^0.24';

    /**
     * What the renderer is allowed to be handed.
     *
     * GET and HEAD are pages. POST is a server action, and the rest are here
     * because a route file is the app's to write — a page that renders a form
     * with method="delete" should reach the renderer rather than a 405 from a
     * framework that was only ever asked about pages.
     */
    private const PROXIED_METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/rsc.php', 'rsc');

        // Scoped, not singleton: what a call invalidated belongs to the request
        // that made it. Under a persistent runtime a singleton would carry one
        // request's marks into the next.
        $this->app->scoped(Revalidation::class);

        // Versions in the app's cache, shared by every server; see Versions.
        $this->app->singleton(Versions::class, fn () => new Versions(
            config('rsc.versions_store'),
            config('rsc.versions', 'cache') === 'database' ? 'database' : 'cache',
            config('rsc.versions_table', 'rsc_versions'),
            config('rsc.versions_connection'),
            (int) config('rsc.versions_keep_days', 30),
            (bool) config('rsc.broadcast', false),
            (string) config('rsc.broadcast_channel', 'rsc-versions'),
        ));

        // Tells the CDN keeping pages that a name changed; see Purge.
        $this->app->singleton(Purge::class, fn () => new Purge(config('rsc.purge_url')));

        $this->app->singleton(CallableRegistry::class, function ($app) {
            $registry = new CallableRegistry($app);

            // Recursive: app/Rsc/Actions and anything nested under either.
            // From the map `rsc:cache` wrote when there is one - production,
            // after `optimize` - and by walking the directory when not.
            $cached = self::callablesCachePath();

            if (is_file($cached)) {
                $registry->registerDiscovered(require $cached);
            } else {
                $registry->discoverFrom(app_path('Rsc'));
            }

            // The reserved name the renderer asks route middleware on.
            // Registered rather than discovered, because it answers the
            // engine's own question and is not one of the app's functions.
            $registry->register(
                RouteMiddleware::FUNCTION,
                fn (array $names = [], ?array $page = null) => (new RouteMiddleware($app))->run($names, $page),
            );

            // And the one it asks tag versions on.
            $registry->register(
                Versions::FUNCTION,
                fn (array $query = []) => $app->make(Versions::class)->answer($query),
            );

            return $registry;
        });

        $this->app->scoped(HostCallDispatcher::class, fn ($app) => new HostCallDispatcher(
            $app->make(CallableRegistry::class),
            $app->make(Revalidation::class),
            (string) config('rsc.host_call_secret'),
            (string) config('rsc.host_call_secret_previous'),
        ));
    }

    public function boot(): void
    {
        // useFlash() reads the message in the browser, which cannot read a
        // cookie Laravel encrypted.
        EncryptCookies::except(Rsc::FLASH_COOKIE);

        $this->registerHostCallEndpoint();
        $this->registerRendererFallback();

        // After every provider, routes/web.php included. Laravel keeps one
        // route per method and uri and the last one registered is it, so a
        // page registered at boot lost `/` to the welcome route loaded after.
        $this->app->booted(fn () => $this->registerRendererPages());

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/rsc.php' => config_path('rsc.php'),
            ], 'rsc-config');

            // Only for `rsc.versions` = database: the table the renderer reads.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'rsc-migrations');

            $this->commands([
                InstallCommand::class,
                MakeActionCommand::class,
                RscHostManifestCommand::class,
                RscCacheCommand::class,
                RscClearCommand::class,
                PruneVersionsCommand::class,
            ]);

            // With route:cache and the rest, so a deploy that already runs
            // `optimize` gets the cached map without a step of its own, and
            // `optimize:clear` takes it away again.
            $this->optimizes(optimize: 'rsc:cache', clear: 'rsc:clear', key: 'rsc');
        }
    }

    /** Where `rsc:cache` writes the discovered callables. */
    public static function callablesCachePath(): string
    {
        return app()->bootstrapPath('cache/rsc-callables.php');
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
     * Every url the React tree has a page or a route.ts for, ahead of the app's.
     *
     * The fallback below only sees what nothing else routed, and a fresh
     * application routes `/` to its welcome page - so after install the page
     * at resources/js/app/page.tsx was unreachable, and the welcome page
     * stood where the docs promised the React tree. The rule the docs state
     * is per url: if the React tree has it, React renders it; otherwise
     * Laravel does. Registered once the application has booted, after
     * routes/web.php: Laravel keeps one route per method and uri, the last
     * registered, so this is what makes the React page the one that answers
     * where the app also declared the url.
     *
     * Read from the table the build writes on every `vite` and `vite build`,
     * so a page added to the tree is routed on the next request with nothing
     * to run. Before the first `vite` there is no table, and only the fallback.
     */
    private function registerRendererPages(): void
    {
        $path = (string) config('rsc.routes_manifest');

        if ($path === '' || ! is_file($path)) {
            return;
        }

        $manifest = json_decode((string) file_get_contents($path), true);

        if (! is_array($manifest)) {
            return;
        }

        // A name the application already gave a route stays its own: a
        // `login` in routes/web.php is still what route('login') means. The
        // lookup is built lazily, so it is refreshed before it is asked, and
        // again after, for the names given here.
        $routes = Route::getRoutes();
        $routes->refreshNameLookups();
        $names = RendererRoutes::names($manifest);
        $declared = RendererRoutes::declaredNames($manifest);

        // rscKit({ subdomains }) names the folders whose subdomain is their only
        // url, and the build writes them into the table. Their pages are routes
        // on that subdomain without the folder, so route('merchant.onboarding')
        // is merchant.example.com/onboarding: as a path on the subdomain it was
        // merchant/merchant/onboarding to the renderer.
        $site = parse_url((string) config('app.url'), PHP_URL_HOST) ?: null;
        $subdomains = array_values(array_filter((array) ($manifest['build']['subdomains'] ?? []), fn ($label) => is_string($label) && $label !== ''));

        // What the application registered, before any page takes a url of its.
        // Not for cached routes: their names were settled when the cache was
        // written, and listing them rebuilds every one.
        $existing = $routes instanceof RouteCollection ? $routes->getRoutesByMethod() : [];

        foreach (RendererRoutes::patterns($manifest) as [$pattern, $catchAll, $methods]) {
            [$domain, $uri] = RendererRoutes::placement($pattern, $subdomains, $site);
            $replaced = $existing[$methods[0]][$domain.(trim($uri, '/') ?: '/')] ?? null;

            // The domain is the group's, so the route has it when it is filed.
            // Set on the route after, it was filed by its path alone, and the
            // site's route at that path was replaced - or replaced it.
            Route::group($domain === null ? [] : ['domain' => $domain], function () use (&$route, $methods, $uri) {
                $route = Route::addRoute($methods, $uri, RendererProxy::class)->middleware('web');
            });

            foreach ($catchAll as $name) {
                $route->where($name, '.*');
            }

            // The name the page declares is the one it answers to, wherever
            // its folder is: `export const name = 'verification.verify'` is
            // what Laravel's notification links to. One the application
            // gives a route of its own at another url is refused rather than
            // decided by registration order: route:cache refuses two routes
            // with one name, and only one of them can be what route() means.
            $own = $declared[$pattern] ?? null;

            if ($own !== null) {
                $taken = $routes->getByName($own);

                // A page's own route is not a clash: from route:cache, the
                // table already has this one under its name.
                if ($taken !== null && $taken !== $replaced && ! str_starts_with($taken->getActionName(), RendererProxy::class)) {
                    throw new LogicException(sprintf(
                        "The page at %s exports name = '%s', and the application names its route %s %s that too. A route has one name: remove one of them.",
                        $uri,
                        $own,
                        implode('|', $taken->methods()),
                        $taken->uri(),
                    ));
                }

                $route->name($own);

                continue;
            }

            // The application's route this page takes the url of is gone, and
            // its name is the page's to keep: route('verification.notice') is
            // what Laravel asks for, not the page's path. With other methods
            // beside GET it is still listed, and still has its name.
            if ($replaced?->getName() !== null && $replaced->methods() === $route->methods()) {
                $route->name($replaced->getName());

                continue;
            }

            $name = in_array('GET', $methods, true) ? ($names[$pattern] ?? null) : null;

            // A path's name another page declared is that page's.
            if ($name !== null && ! in_array($name, $declared, true) && ! $routes->hasNamedRoute($name)) {
                $route->name($name);
            }
        }

        $routes->refreshNameLookups();
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
        //
        // Every method, not Route::fallback(), which registers GET and HEAD
        // only. A server action is a POST to /_rsc/action — a path Laravel does
        // not route — so the GET-only fallback matched the uri, refused the
        // method, and answered 405. The action never reached the renderer, and
        // the browser decoded a failed row and unmounted the document: a blank
        // page, from a working application, for every action ever submitted
        // through this proxy.
        //
        // ->fallback() is what keeps it last regardless of the methods, so a
        // real route still wins. Registering this as an ordinary any-method
        // route would shadow anything declared after it.
        Route::addRoute(self::PROXIED_METHODS, '{rscFallbackPlaceholder}', RendererProxy::class)
            ->where('rscFallbackPlaceholder', '.*')
            ->fallback()
            ->middleware('web');
    }
}
