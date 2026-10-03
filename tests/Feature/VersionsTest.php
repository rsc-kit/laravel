<?php

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RscKit\CallableRegistry;
use RscKit\Events\VersionsChanged;
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

/** The current time in ms: what a version is at least, once bumped now. */
function nowMs(): int
{
    return (int) floor(microtime(true) * 1000);
}

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
    $before = nowMs();

    Rsc::changed('orders');
    $first = askChanged(['orders' => -1])['orders'];
    Rsc::changed('orders', 'orders'); // named twice in one call: one change
    $second = askChanged(['orders' => -1])['orders'];

    expect($first)->toBeGreaterThanOrEqual($before);
    expect($second)->toBeGreaterThan($first);
    expect(askChanged(['orders' => $second, 'quiet' => 0]))->toBe([]);
});

it('moves a version to the larger of one more and now, so it never repeats', function () {
    expect(Versions::next(0))->toBeGreaterThanOrEqual(nowMs() - 1);
    expect(Versions::next(7))->toBeGreaterThan(1_700_000_000_000);

    $ahead = nowMs() + 3_600_000;

    expect(Versions::next($ahead))->toBe($ahead + 1);
});

it('lets a cache key expire on its own, after rsc.versions_keep_days', function () {
    app()->forgetInstance(Versions::class);
    app()->singleton(Versions::class, fn () => new Versions(keepDays: 2));

    Rsc::changed('orders');
    expect(Cache::get(Versions::PREFIX.'orders'))->not->toBeNull();

    $this->travel(3)->days();
    expect(Cache::get(Versions::PREFIX.'orders'))->toBeNull();

    // Changed again, it comes back past anything a tab could hold.
    Rsc::changed('orders');
    expect(Cache::get(Versions::PREFIX.'orders'))->toBeGreaterThanOrEqual(nowMs() - 1);
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

    expect(Cache::store('names')->get(Versions::PREFIX.'orders'))->toBeGreaterThan(1_700_000_000_000);
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
        $first = (int) DB::table('rsc_versions')->where('name', 'orders')->value('version');
        Rsc::changed('orders', 'orders', 'stock');

        $versions = DB::table('rsc_versions')->orderBy('name')->pluck('version', 'name')->map(fn ($v) => (int) $v)->all();

        expect($versions['orders'])->toBeGreaterThan($first);
        expect($versions['stock'])->toBeGreaterThan(1_700_000_000_000);
        expect(askChanged(['orders' => 0, 'stock' => $versions['stock'], 'quiet' => 0]))->toBe(['orders' => $versions['orders']]);
    });

    it('moves a counter row an older writer left to the time', function () {
        DB::table('rsc_versions')->insert(['name' => 'counted', 'version' => 7]);

        Rsc::changed('counted');

        expect((int) DB::table('rsc_versions')->where('name', 'counted')->value('version'))->toBeGreaterThanOrEqual(nowMs() - 1000);
    });

    it('writes the table every store reads: name and version, nothing else', function () {
        Rsc::changed('restoration:42');

        $row = (array) DB::table('rsc_versions')->first();

        expect(array_keys($row))->toBe(['name', 'version']);
        expect($row['name'])->toBe('restoration:42');
    });

    it('prunes names not changed in a while, and a pruned name comes back past what a tab holds', function () {
        $held = nowMs() - 365 * 86_400_000;

        DB::table('rsc_versions')->insert(['name' => 'old', 'version' => $held]);
        Rsc::changed('fresh');

        $this->artisan('rsc:prune-versions')->expectsOutputToContain('Deleted 1 name(s)')->assertSuccessful();

        expect(DB::table('rsc_versions')->pluck('name')->all())->toBe(['fresh']);
        // Pruned, it reads as 0 - different from what the tab holds, so it refreshes once.
        expect(askChanged(['old' => $held]))->toBe(['old' => 0]);

        Rsc::changed('old');
        expect((int) DB::table('rsc_versions')->where('name', 'old')->value('version'))->toBeGreaterThan($held);
    });

    it('prunes by --days', function () {
        DB::table('rsc_versions')->insert(['name' => 'week', 'version' => nowMs() - 8 * 86_400_000]);

        $this->artisan('rsc:prune-versions', ['--days' => 30])->assertSuccessful();
        expect(DB::table('rsc_versions')->count())->toBe(1);

        $this->artisan('rsc:prune-versions', ['--days' => 7])->assertSuccessful();
        expect(DB::table('rsc_versions')->count())->toBe(0);
    });

    it('leaves the cache alone', function () {
        Rsc::changed('orders');

        expect(Cache::get(Versions::PREFIX.'orders'))->toBeNull();
    });
});

it('has nothing to prune in cache mode: keys expire on their own', function () {
    $this->artisan('rsc:prune-versions')->expectsOutputToContain('expire on their own')->assertSuccessful();
});

describe('broadcasting a change', function () {
    it('wakes listening renderers when rsc.broadcast is on, and not otherwise', function () {
        Event::fake([VersionsChanged::class]);

        Rsc::changed('inbox:7');
        Event::assertNotDispatched(VersionsChanged::class);

        app()->forgetInstance(Versions::class);
        app()->singleton(Versions::class, fn () => new Versions(broadcast: true));

        Rsc::changed('inbox:7', 'conversation:42');
        Event::assertDispatchedTimes(VersionsChanged::class, 1);
    });

    it('carries no names: the channel is public, and names may be one visitor\'s', function () {
        $event = new VersionsChanged;

        expect($event->broadcastWith())->toBe([])
            ->and($event->broadcastOn()->name)->toBe('rsc-versions')
            ->and($event->broadcastAs())->toBe('rsc.changed')
            ->and($event)->toBeInstanceOf(ShouldDispatchAfterCommit::class);
    });

    it('broadcasts on the channel the config names', function () {
        expect((new VersionsChanged('my-app-versions'))->broadcastOn()->name)->toBe('my-app-versions');
    });
});
