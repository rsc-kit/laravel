<?php

use Illuminate\Support\Facades\Config;
/**
 * Action discovery reads the app's config, so it belongs with the suite that
 * boots the framework. The rendering half - the "use server" stubs and their
 * declarations - is the engine's, from the map this writes.
 */

use Illuminate\Support\Facades\File;
use RscKit\CallableRegistry;
use RscKit\Support\ActionManifest;
use RscKit\Support\RscPaths;

test('discovery maps a class to a module, and its methods to the module\'s exports', function () {
    $dir = sys_get_temp_dir().'/rsc-actions-'.uniqid();
    mkdir($dir, 0755, true);

    file_put_contents($dir.'/TodoActions.php', <<<'CLASS'
<?php
namespace RscTestActions;
class TodoActions
{
    public function add(string $title): array { return []; }
    public function toggle(string $id): array { return []; }
    public static function ignored(): void {}
}
CLASS);

    require $dir.'/TodoActions.php';
    Config::set('rsc.actions_dir', $dir);

    $actions = ActionManifest::discover();

    expect($actions)->toBe([
        'todo-actions' => ['add' => 'TodoActions.add', 'toggle' => 'TodoActions.toggle'],
    ]);

    unlink($dir.'/TodoActions.php');
    rmdir($dir);
});

test('magic methods are not actions', function () {
    // __call would take any name and any arguments from a browser; __toString
    // and __destruct are PHP's hooks, not the app's. __invoke stays an action.
    $dir = sys_get_temp_dir().'/rsc-actions-'.uniqid();
    mkdir($dir, 0755, true);

    file_put_contents($dir.'/Magic.php', <<<'CLASS'
<?php
namespace RscTestActions;
class Magic
{
    public function save(): void {}
    public function __call($name, $args) {}
    public function __toString(): string { return ''; }
    public function __destruct() {}
}
CLASS);

    require $dir.'/Magic.php';
    Config::set('rsc.actions_dir', $dir);

    expect(ActionManifest::discover())->toBe(['magic' => ['save' => 'Magic.save']]);

    unlink($dir.'/Magic.php');
    rmdir($dir);
});

test('a docblock that says "class" does not hide the class', function () {
    // A pattern for `class Name` matched the first place the words met - here
    // "class handles" - went looking for RscTestDocblock\handles, and skipped
    // the class the file actually declared without a word.
    $dir = sys_get_temp_dir().'/rsc-actions-'.uniqid();
    mkdir($dir, 0755, true);

    file_put_contents($dir.'/Refunds.php', <<<'CLASS'
<?php
namespace RscTestDocblock;

use Illuminate\Routing\Attributes\Controllers\Middleware;

/**
 * This class handles refunds, and a class like it would too.
 */
#[Middleware(\stdClass::class)]
class Refunds
{
    public function issue(): string { return 'issued'; }
}
CLASS);

    require $dir.'/Refunds.php';
    Config::set('rsc.actions_dir', $dir);

    expect(ActionManifest::discover())->toBe(['refunds' => ['issue' => 'Refunds.issue']]);
    expect(CallableRegistry::discover($dir))->toBe(['Refunds.issue' => ['RscTestDocblock\Refunds', 'issue']]);

    unlink($dir.'/Refunds.php');
    rmdir($dir);
});

test('the actions directory is in the published config, with its default', function () {
    // Read by discovery and missing from config/rsc.php, so an application
    // could not see it to change it.
    $config = require dirname(__DIR__, 2).'/config/rsc.php';

    expect($config)->toHaveKey('actions_dir')->toHaveKey('dir');
    expect($config['dir'])->toBe(app_path('Rsc'));

    // Null in the file, so moving `dir` moves the actions with it.
    Config::set('rsc.dir', $config['dir']);
    Config::set('rsc.actions_dir', $config['actions_dir']);
    expect(RscPaths::actions())->toBe(app_path('Rsc/Actions'));
});

test('a missing actions directory discovers nothing', function () {
    Config::set('rsc.actions_dir', '/nonexistent/app/Rsc/Actions');

    expect(ActionManifest::discover())->toBe([]);
});

test('the written manifest is an object even with nothing in it', function () {
    // json_encode writes an empty PHP array as [], and the build reads a
    // map. A fresh install with no actions yet wrote [] and the first `dev`
    // refused to start over it.
    Config::set('rsc.actions_dir', '/nonexistent/app/Rsc/Actions');

    $this->artisan('rsc:host-manifest', ['--print' => true])
        ->expectsOutputToContain('"actions": {}')
        ->assertSuccessful();
});

test('a nested class is a module in the same folder, and an __invoke is its default export', function () {
    $dir = sys_get_temp_dir().'/rsc-actions-'.uniqid();
    mkdir($dir.'/Billing', 0755, true);

    file_put_contents($dir.'/Billing/SendInvoice.php', <<<'CLASS'
<?php
namespace RscTestModules\Billing;
class SendInvoice
{
    public function __invoke(int $id): void {}
}
CLASS);
    file_put_contents($dir.'/Orders.php', <<<'CLASS'
<?php
namespace RscTestModules;
class Orders
{
    public function delete(int $id): void {}
}
CLASS);

    require $dir.'/Billing/SendInvoice.php';
    require $dir.'/Orders.php';
    Config::set('rsc.actions_dir', $dir);

    // delete is a word JavaScript keeps; the engine exports it under its own
    // name all the same, so the map says delete.
    expect(ActionManifest::discover())->toBe([
        'billing/send-invoice' => ['default' => 'SendInvoice'],
        'orders' => ['delete' => 'Orders.delete'],
    ]);

    File::deleteDirectory($dir);
});

test('two classes the endpoint would answer with one name are refused, not the second silently lost', function () {
    // The registry answers Invoices.create with the first class it finds, so
    // the second module's stub would post to the first one's method.
    $dir = sys_get_temp_dir().'/rsc-actions-'.uniqid();
    mkdir($dir.'/Billing', 0755, true);

    file_put_contents($dir.'/Billing/Invoices.php', <<<'CLASS'
<?php
namespace RscTestTwice\Billing;
class Invoices
{
    public function create(): void {}
}
CLASS);
    file_put_contents($dir.'/Invoices.php', <<<'CLASS'
<?php
namespace RscTestTwice;
class Invoices
{
    public function create(): void {}
}
CLASS);

    require $dir.'/Billing/Invoices.php';
    require $dir.'/Invoices.php';
    Config::set('rsc.actions_dir', $dir);

    expect(fn () => ActionManifest::discover())
        ->toThrow(RuntimeException::class, 'RscTestTwice\Billing\Invoices and RscTestTwice\Invoices are both Invoices.create');

    File::deleteDirectory($dir);
});

test('the manifest writes each module as an object', function () {
    $dir = sys_get_temp_dir().'/rsc-actions-'.uniqid();
    mkdir($dir, 0755, true);

    file_put_contents($dir.'/Pings.php', <<<'CLASS'
<?php
namespace RscTestJson;
class Pings
{
    public function send(): void {}
}
CLASS);

    require $dir.'/Pings.php';
    Config::set('rsc.actions_dir', $dir);

    $this->artisan('rsc:host-manifest', ['--print' => true])
        ->expectsOutputToContain('"pings": {')
        ->assertSuccessful();

    File::deleteDirectory($dir);
});
