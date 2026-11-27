<?php

use Illuminate\Support\Facades\Route;
use RscKit\RscKitServiceProvider;

/**
 * A name a page declares that the application also gives a route of its own.
 *
 * Refused at boot rather than settled by registration order: route:cache
 * refuses two routes of one name, and only one of them can be what route()
 * means. The route the page replaces is not a clash - it is gone, and the
 * page is at its url.
 */
function registerPages(array $routes): void
{
    $manifest = sys_get_temp_dir().'/rsc-declared-'.getmypid().'.json';
    file_put_contents($manifest, json_encode(['routes' => $routes]));
    config()->set('rsc.routes_manifest', $manifest);

    try {
        (fn () => $this->registerRendererPages())->call(app()->getProvider(RscKitServiceProvider::class));
    } finally {
        @unlink($manifest);
    }
}

it('refuses a declared name the application gives a route at another url', function () {
    Route::get('/billing', fn () => 'mine')->name('billing');

    registerPages([['segments' => [['type' => 'static', 'value' => 'plans']], 'routeName' => 'billing']]);
})->throws(LogicException::class, "The page at /plans exports name = 'billing', and the application names its route GET|HEAD billing that too.");

it('takes the name of the route it replaces at the same url', function () {
    Route::get('/confirm/{id}', fn () => 'mine')->name('tickets.confirm');

    registerPages([['segments' => [['type' => 'static', 'value' => 'confirm'], ['type' => 'param', 'value' => 'id']], 'routeName' => 'tickets.confirm']]);

    expect(route('tickets.confirm', ['id' => 1], false))->toBe('/confirm/1')
        ->and(Route::getRoutes()->getByName('tickets.confirm')->getActionName())->toStartWith('RscKit\Http\RendererProxy');
});
