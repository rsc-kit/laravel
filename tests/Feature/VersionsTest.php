<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

describe('kept in the rsc_versions table', function () {
    beforeEach(function () {
        (require __DIR__.'/../../database/migrations/2026_10_03_000000_create_rsc_versions_table.php')->up();

        config()->set('rsc.versions', 'database');
        app()->forgetInstance(Versions::class);
    });

    it('moves a version with one upsert per name, and answers from the table', function () {
        Rsc::changed('orders');
        Rsc::changed('orders', 'orders', 'stock');

        expect(DB::table('rsc_versions')->orderBy('name')->pluck('version', 'name')->map(fn ($v) => (int) $v)->all())
            ->toBe(['orders' => 2, 'stock' => 1]);
        expect(askChanged(['orders' => 0, 'stock' => 1, 'quiet' => 0]))->toBe(['orders' => 2]);
    });

    it('writes the table every store reads: name and version, nothing else', function () {
        Rsc::changed('restoration:42');

        expect((array) DB::table('rsc_versions')->first())->toBe(['name' => 'restoration:42', 'version' => 1]);
    });

    it('leaves the cache alone', function () {
        Rsc::changed('orders');

        expect(Cache::get(Versions::PREFIX.'orders'))->toBeNull();
    });
});
