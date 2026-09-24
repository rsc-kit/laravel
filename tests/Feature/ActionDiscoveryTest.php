<?php

/**
 * Action discovery reads the app's config, so it belongs with the suite that
 * boots the framework. The rendering half - the "use server" stubs and their
 * declarations - is the engine's, from the map this writes.
 */

use Illuminate\Support\Facades\Config;
use RscKit\CallableRegistry;
use RscKit\Support\ActionManifest;

test('discovery maps class methods to camelCase JS names', function () {
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
        'todoActionsAdd' => 'TodoActions.add',
        'todoActionsToggle' => 'TodoActions.toggle',
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

    expect(ActionManifest::discover())->toBe(['magicSave' => 'Magic.save']);

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

    expect(ActionManifest::discover())->toBe(['refundsIssue' => 'Refunds.issue']);
    expect(CallableRegistry::discover($dir))->toBe(['Refunds.issue' => ['RscTestDocblock\Refunds', 'issue']]);

    unlink($dir.'/Refunds.php');
    rmdir($dir);
});

test('the actions directory is in the published config, with its default', function () {
    // Read by discovery and missing from config/rsc.php, so an application
    // could not see it to change it.
    $config = require dirname(__DIR__, 2).'/config/rsc.php';

    expect($config)->toHaveKey('actions_dir');
    expect($config['actions_dir'])->toBe(app_path('Rsc/Actions'));
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

    $this->artisan('rsc:action-manifest', ['--print' => true])
        ->expectsOutput('{}')
        ->assertSuccessful();
});
