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

it('is what the manifest discovers', function () {
    $this->artisan('make:rsc-action', ['name' => 'Orders', '--method' => ['cancel']])->assertSuccessful();

    // Discovery goes through the autoloader, which knows nothing of a class
    // written a moment ago into the test application; loaded by hand.
    require app_path('Rsc/Actions/Orders.php');
    config()->set('rsc.actions_dir', app_path('Rsc/Actions'));

    expect(ActionManifest::discover())->toBe(['ordersCancel' => 'Orders.cancel']);
});
