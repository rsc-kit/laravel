<?php

use Illuminate\Support\Facades\File;
use RscKit\Support\ActionManifest;

/**
 * make:rsc-action writes the class the guide shows, guards included: the
 * attributes it writes are the ones the registry reads, so a guard asked for
 * at the prompt is the guard that runs.
 */
beforeEach(function () {
    File::deleteDirectory(app_path('Rsc'));
});

afterEach(function () {
    File::deleteDirectory(app_path('Rsc'));
});

it('writes a server action with its methods and guards', function () {
    $this->artisan('make:rsc-action', [
        'name' => 'Orders',
        '--method' => ['cancel', 'refund'],
        '--auth' => true,
        '--can' => ['update,Order'],
        '--middleware' => ['throttle:60,1'],
        '--revalidate' => 'orders',
    ])->assertSuccessful();

    $source = File::get(app_path('Rsc/Actions/Orders.php'));

    expect($source)
        ->toContain('namespace App\Rsc\Actions;')
        ->toContain('#[Authenticated]')
        ->toContain("#[Can('update', Order::class)]")
        ->toContain('use App\Models\Order;')
        ->toContain("#[Middleware('throttle:60,1')]")
        ->toContain('public function cancel(): mixed')
        ->toContain('public function refund(): mixed')
        ->toContain("Rsc::revalidate('orders');");
});

it('writes an invokable class when no method is named, and nests a slashed name', function () {
    $this->artisan('make:rsc-action', ['name' => 'Billing/Invoices'])->assertSuccessful();

    $source = File::get(app_path('Rsc/Actions/Billing/Invoices.php'));

    expect($source)
        ->toContain('namespace App\Rsc\Actions\Billing;')
        ->toContain('public function __invoke(): mixed')
        ->not->toContain("Rsc::revalidate('");
});

it('writes an rpc class under app/Rsc with --rpc, and never a revalidate', function () {
    $this->artisan('make:rsc-action', ['name' => 'Orders', '--rpc' => true, '--method' => ['recent'], '--revalidate' => 'orders'])
        ->assertSuccessful();

    $source = File::get(app_path('Rsc/Orders.php'));

    expect($source)
        ->toContain('namespace App\Rsc;')
        ->toContain('public function recent(): mixed')
        ->not->toContain("Rsc::revalidate('");
});

it('refuses to overwrite without --force', function () {
    $this->artisan('make:rsc-action', ['name' => 'Orders'])->assertSuccessful();
    File::put(app_path('Rsc/Actions/Orders.php'), '<?php // mine');

    $this->artisan('make:rsc-action', ['name' => 'Orders'])->assertFailed();
    expect(File::get(app_path('Rsc/Actions/Orders.php')))->toBe('<?php // mine');

    $this->artisan('make:rsc-action', ['name' => 'Orders', '--force' => true])->assertSuccessful();
    expect(File::get(app_path('Rsc/Actions/Orders.php')))->toContain('class Orders');
});

it('is what the manifest discovers, and writes the manifest itself', function () {
    config()->set('rsc.actions_dir', app_path('Rsc/Actions'));
    File::delete(base_path('rsc-host-actions.json'));

    // A name no other test writes: the command loads the class by path, and
    // a class, once declared in this process, stays declared.
    $this->artisan('make:rsc-action', ['name' => 'Shipments', '--method' => ['track']])->assertSuccessful();

    // Under a running dev server the map is what the stub is generated from,
    // and the server starts again when the file changes - so the command
    // writes it rather than leaving it to the dev script's next run.
    expect(ActionManifest::discover())->toBe(['shipmentsTrack' => 'Shipments.track'])
        ->and(json_decode(File::get(base_path('rsc-host-actions.json')), true))->toBe(['shipmentsTrack' => 'Shipments.track']);

    File::delete(base_path('rsc-host-actions.json'));
});

it('a nested class is discovered too', function () {
    config()->set('rsc.actions_dir', app_path('Rsc/Actions'));

    $this->artisan('make:rsc-action', ['name' => 'Billing/Statements', '--method' => ['send']])->assertSuccessful();

    // A glob of the top level alone never saw Billing/Statements.php: the
    // command said created, and the stub was not there to import.
    expect(ActionManifest::discover())->toBe(['statementsSend' => 'Statements.send']);

    File::delete(base_path('rsc-host-actions.json'));
});

it('an rpc class writes no manifest, since the map is of server actions', function () {
    File::delete(base_path('rsc-host-actions.json'));

    $this->artisan('make:rsc-action', ['name' => 'Orders', '--rpc' => true, '--method' => ['recent']])->assertSuccessful();

    expect(File::exists(base_path('rsc-host-actions.json')))->toBeFalse();
});
