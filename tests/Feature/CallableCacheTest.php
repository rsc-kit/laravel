<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\ServiceProvider;
use RscKit\CallableRegistry;
use RscKit\RscKitServiceProvider;

/**
 * The discovered callables, written down for production.
 *
 * Under FPM the registry is built for every request, and building it walked
 * app/Rsc, read and tokenized every file and reflected on every class before
 * the one function the call asked for could run. `rsc:cache` does that once;
 * the registry reads the map while it exists and walks the directory when it
 * does not, so development never sees a stale one.
 */
beforeEach(function () {
    File::deleteDirectory(app_path('Rsc'));
    File::delete(RscKitServiceProvider::callablesCachePath());
});

afterEach(function () {
    File::deleteDirectory(app_path('Rsc'));
    File::delete(RscKitServiceProvider::callablesCachePath());
    File::delete(base_path('rsc-host-actions.json'));
});

it('writes what discovery finds', function () {
    $this->artisan('make:rsc-action', ['name' => 'Tallies', '--rpc' => true, '--method' => ['count']])->assertSuccessful();
    require_once app_path('Rsc/Tallies.php');

    $this->artisan('rsc:cache')->assertSuccessful();

    expect(require RscKitServiceProvider::callablesCachePath())
        ->toBe(['Tallies.count' => ['App\Rsc\Tallies', 'count']]);
});

it('builds the registry from the map instead of walking the directory', function () {
    File::ensureDirectoryExists(dirname(RscKitServiceProvider::callablesCachePath()));
    File::put(RscKitServiceProvider::callablesCachePath(), "<?php return ['Only.inTheMap' => ['App\\\\Rsc\\\\Nowhere', 'run']];");

    app()->forgetInstance(CallableRegistry::class);

    expect(app(CallableRegistry::class)->names())->toContain('Only.inTheMap');
});

it('is forgotten by rsc:clear', function () {
    $this->artisan('rsc:cache')->assertSuccessful();
    expect(File::exists(RscKitServiceProvider::callablesCachePath()))->toBeTrue();

    $this->artisan('rsc:clear')->assertSuccessful();
    expect(File::exists(RscKitServiceProvider::callablesCachePath()))->toBeFalse();
});

it('runs with optimize, and is cleared with optimize:clear', function () {
    expect(ServiceProvider::$optimizeCommands)->toHaveKey('rsc', 'rsc:cache');
    expect(ServiceProvider::$optimizeClearCommands)->toHaveKey('rsc', 'rsc:clear');
});

it('keeps a class make:rsc-action writes callable while a map exists', function () {
    // The registry reads only the map while there is one, so a class written
    // after it was would not exist to the endpoint until someone thought to
    // run optimize again.
    $this->artisan('rsc:cache')->assertSuccessful();

    $this->artisan('make:rsc-action', ['name' => 'Ledgers', '--rpc' => true, '--method' => ['balance']])->assertSuccessful();

    expect(require RscKitServiceProvider::callablesCachePath())
        ->toHaveKey('Ledgers.balance');
});
