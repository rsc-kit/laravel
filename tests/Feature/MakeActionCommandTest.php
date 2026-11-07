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
    File::delete(base_path('rsc-host.json'));

    // A name no other test writes: the command loads the class by path, and
    // a class, once declared in this process, stays declared.
    $this->artisan('make:rsc-action', ['name' => 'Shipments', '--method' => ['track']])->assertSuccessful();

    // Under a running dev server the map is what the stub is generated from,
    // and the server starts again when the file changes - so the command
    // writes it rather than leaving it to the dev script's next run.
    expect(ActionManifest::discover())->toBe(['shipmentsTrack' => 'Shipments.track'])
        ->and(json_decode(File::get(base_path('rsc-host.json')), true)['actions'])->toBe(['shipmentsTrack' => 'Shipments.track'])
        ->and(json_decode(File::get(base_path('rsc-host.json')), true)['functions'])->toContain('Shipments.track');

    File::delete(base_path('rsc-host.json'));
});

it('a nested class is discovered too', function () {
    config()->set('rsc.actions_dir', app_path('Rsc/Actions'));

    $this->artisan('make:rsc-action', ['name' => 'Billing/Statements', '--method' => ['send']])->assertSuccessful();

    // A glob of the top level alone never saw Billing/Statements.php: the
    // command said created, and the stub was not there to import.
    expect(ActionManifest::discover())->toBe(['statementsSend' => 'Statements.send']);

    File::delete(base_path('rsc-host.json'));
});

it('an rpc class is in the manifest as a function, never as an action', function () {
    File::delete(base_path('rsc-host.json'));

    $this->artisan('make:rsc-action', ['name' => 'Almanacs', '--rpc' => true, '--method' => ['recent']])->assertSuccessful();

    // Its names type rpc(), so a misspelt one fails the app's typecheck.
    // A name no other test declares: a class stays declared for the process.
    $manifest = json_decode(File::get(base_path('rsc-host.json')), true);

    expect($manifest['functions'])->toContain('Almanacs.recent')
        ->and($manifest['functions'])->not->toContain('__rsc.middleware')
        ->and($manifest['actions'])->not->toHaveKey('almanacsRecent');

    File::delete(base_path('rsc-host.json'));
});

it('writes a public form whole with --form: the request, the action taking it, and the form', function () {
    File::delete(app_path('Http/Requests/SendContact.php'));
    File::deleteDirectory(resource_path('js/forms'));

    $this->artisan('make:rsc-action', ['name' => 'Contact', '--method' => ['send'], '--form' => true])
        ->assertSuccessful();

    expect(File::get(app_path('Rsc/Actions/Contact.php')))
        ->toContain('use App\Http\Requests\SendContact;')
        ->toContain('public function send(SendContact $request): void');

    expect(File::get(app_path('Http/Requests/SendContact.php')))
        ->toContain('namespace App\Http\Requests;')
        ->toContain('class SendContact extends FormRequest')
        ->toContain('return true;');

    expect(File::get(resource_path('js/forms/contact.ts')))
        ->toContain("import { contactSend } from '../server-actions.generated'")
        ->toContain("name: 'contact',")
        ->toContain('action: contactSend,');

    expect(ActionManifest::discover())->toHaveKey('contactSend', 'Contact.send');

    File::delete(app_path('Http/Requests/SendContact.php'));
    File::deleteDirectory(resource_path('js/forms'));
});

it('refuses --form with more than one method, or for rpc', function () {
    $this->artisan('make:rsc-action', ['name' => 'Contact', '--method' => ['send', 'other'], '--form' => true])->assertFailed();
    $this->artisan('make:rsc-action', ['name' => 'Contact', '--rpc' => true, '--form' => true])->assertFailed();

    expect(File::exists(app_path('Rsc/Actions/Contact.php')))->toBeFalse();
});
