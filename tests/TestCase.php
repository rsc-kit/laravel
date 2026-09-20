<?php

namespace Tests;

use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RscKit\RscKitServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            RscKitServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Both keys the old runtime needed are gone: there is no socket to
        // point at and nothing to enable. What a test needs now is a secret,
        // because the endpoint is not registered without one.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        // A route table the build would have written, for the one file that
        // tests the renderer's pages being registered ahead of the app's -
        // which has to be decided before the package boots, so it travels
        // as an environment variable the file sets at load.
        if ($manifest = getenv('RSC_TEST_ROUTES_MANIFEST')) {
            $app['config']->set('rsc.routes_manifest', $manifest);

            // The application's own routes, the way routes/web.php arrives:
            // in a booted callback registered before the package's, since the
            // app's RouteServiceProvider registers it at register time and
            // the package registers its own at boot.
            $app->booted(function () {
                Route::get('/', fn () => 'welcome');
                Route::get('/orders/{id}', fn () => 'mine');
                Route::get('/docs/{rest}', fn () => 'mine')->where('rest', '.*');
                Route::get('/api/health', fn () => 'mine');
                Route::post('/api/health', fn () => 'posted here');
                Route::get('/login', fn () => 'laravel login');
            });
        }
    }
}
