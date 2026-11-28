<?php

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use RscKit\CallableRegistry;
use RscKit\Support\ActionManifest;
use RscKit\Support\RscPaths;

/**
 * config('rsc.dir') moves the folder the app's callables live in - app/Rsc by
 * default, app/Http/Rsc for an app that keeps them beside its controllers.
 * Discovery, the action manifest and make:rsc-action all follow it, and a
 * callable keeps its name, since the name is the class's short name.
 */
beforeEach(function () {
    File::deleteDirectory(app_path('Http/Rsc'));
    Config::set('rsc.dir', app_path('Http/Rsc'));
    Config::set('rsc.actions_dir', null);
});

afterEach(function () {
    File::deleteDirectory(app_path('Http/Rsc'));
    File::delete(base_path('rsc-host.json'));
});

it('puts the actions inside the folder unless they are set apart', function () {
    expect(RscPaths::root())->toBe(app_path('Http/Rsc'))
        ->and(RscPaths::actions())->toBe(app_path('Http/Rsc/Actions'));

    Config::set('rsc.actions_dir', '/somewhere/else');

    expect(RscPaths::actions())->toBe('/somewhere/else');
});

it('writes a server action and an rpc class there, in its namespace', function () {
    $this->artisan('make:rsc-action', ['name' => 'Billing/Ledgers', '--method' => ['close']])->assertSuccessful();
    $this->artisan('make:rsc-action', ['name' => 'Receipts', '--rpc' => true, '--method' => ['latest']])->assertSuccessful();

    expect(File::get(app_path('Http/Rsc/Actions/Billing/Ledgers.php')))->toContain('namespace App\Http\Rsc\Actions\Billing;')
        ->and(File::get(app_path('Http/Rsc/Receipts.php')))->toContain('namespace App\Http\Rsc;')
        ->and(File::exists(app_path('Rsc/Receipts.php')))->toBeFalse();
});

it('discovers what is there under the same names', function () {
    $this->artisan('make:rsc-action', ['name' => 'Ledgers', '--method' => ['close']])->assertSuccessful();
    $this->artisan('make:rsc-action', ['name' => 'Receipts', '--rpc' => true, '--method' => ['latest']])->assertSuccessful();

    expect(CallableRegistry::discover(RscPaths::root()))
        ->toHaveKey('Ledgers.close', ['App\Http\Rsc\Actions\Ledgers', 'close'])
        ->toHaveKey('Receipts.latest', ['App\Http\Rsc\Receipts', 'latest'])
        ->and(ActionManifest::discover())->not->toBe([]);
});

it('refuses a folder outside app/, which has no namespace to write', function () {
    Config::set('rsc.dir', base_path('rsc'));

    $this->artisan('make:rsc-action', ['name' => 'Ledgers'])
        ->expectsOutputToContain('outside app/')
        ->assertFailed();

    expect(File::exists(base_path('rsc/Actions/Ledgers.php')))->toBeFalse();
});
