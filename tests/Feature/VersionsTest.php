<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallDispatcher;
use RscKit\Rsc;
use RscKit\Versions;

/**
 * Versions: moved by Rsc::changed() from anywhere, read by the renderer
 * through the reserved function for every open tab.
 */
beforeEach(function () {
    config()->set('rsc.host_call_secret', 'names-secret');
    Cache::flush();
});

function askChanged(array $since): array
{
    $answer = app(HostCallDispatcher::class)->dispatch([
        'function' => Versions::FUNCTION,
        'args' => [['since' => $since, 'wait' => 5000]],
    ]);

    expect($answer['status'])->toBe(200);

    return (array) $answer['reply']['result']['versions'];
}

it('answers a name nobody changed as 0, and unchanged from 0', function () {
    expect(askChanged(['orders' => -1]))->toBe(['orders' => 0]);
    expect(askChanged(['orders' => 0]))->toBe([]);
});

it('moves a version when a name is said to have changed, and answers only what differs', function () {
    Rsc::changed('orders');
    Rsc::changed('orders', 'orders'); // named twice in one call: one change
    Rsc::changed('stock');

    expect(askChanged(['orders' => 0, 'stock' => 1, 'quiet' => 0]))->toBe(['orders' => 2]);
});

it('writes an empty answer as an object, not a list', function () {
    $json = app(HostCallDispatcher::class)->respond([
        'function' => Versions::FUNCTION,
        'args' => [['since' => ['orders' => 0]]],
    ])['json'];

    expect($json)->toContain('"versions":{}');
});

it('keeps the versions in the store the config names', function () {
    config()->set('cache.stores.names', ['driver' => 'array']);
    app()->forgetInstance(Versions::class);
    app()->singleton(Versions::class, fn () => new Versions('names'));

    Rsc::changed('orders');

    expect(Cache::store('names')->get(Versions::PREFIX.'orders'))->toBe(1);
    expect(Cache::get(Versions::PREFIX.'orders'))->toBeNull();
});

it('is the registry\'s own, not a function of the app', function () {
    expect(app(CallableRegistry::class)->names())->toContain(Versions::FUNCTION);

    Artisan::call('rsc:host-manifest', ['--print' => true]);

    $manifest = json_decode(Artisan::output(), true);

    expect($manifest['functions'])->not->toContain(Versions::FUNCTION);
});
