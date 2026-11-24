<?php

use Illuminate\Support\Facades\Artisan;
use RscKit\CallableRegistry;
use RscKit\Support\HostSources;

beforeEach(function () {
    $this->dir = base_path('app/Rsc-'.uniqid());
    mkdir($this->dir, 0755, true);
    $this->ns = 'HostSources'.uniqid();

    file_put_contents($this->dir.'/Shared.php', <<<PHP
<?php
namespace {$this->ns};
trait Shared
{
    public function recent(): array { return []; }
}
PHP);
    file_put_contents($this->dir.'/LoginPage.php', <<<PHP
<?php

namespace {$this->ns};

class LoginPage
{
    use Shared;

    /** What the page is given. */
    #[\\RscKit\\Attributes\\Authenticated]
    public function props(): array
    {
        return [];
    }

    public function __invoke(): void {}
}
PHP);
    require_once $this->dir.'/Shared.php';
    require_once $this->dir.'/LoginPage.php';

    $this->callables = CallableRegistry::discover($this->dir);
});

afterEach(function () {
    array_map(unlink(...), glob($this->dir.'/*.php'));
    rmdir($this->dir);
});

test('a method is where it is written, past its attributes', function () {
    $at = HostSources::describe($this->callables)['LoginPage.props'];

    expect($at['file'])->toBe('app/'.basename($this->dir).'/LoginPage.php')
        ->and($at['line'])->toBe(11)
        ->and($at['name'])->toBe($this->ns.'\\LoginPage::props');
});

test("a trait's method is in the trait, under the class that is called", function () {
    $at = HostSources::describe($this->callables)['LoginPage.recent'];

    expect($at['file'])->toEndWith('/Shared.php')
        ->and($at['line'])->toBe(5)
        ->and($at['name'])->toBe($this->ns.'\\LoginPage::recent');
});

test('an invokable is its __invoke', function () {
    expect(HostSources::describe($this->callables)['LoginPage']['line'])->toBe(16);
});

test('one outside the application is left out, so no path from this machine is committed', function () {
    $outside = fn () => null;

    expect(HostSources::describe(['Outside' => $outside, 'Builtin' => strlen(...), 'Gone' => ['No\\Such', 'method']]))->toBe([]);
});

test('the manifest carries it, as an object even when empty', function () {
    Artisan::call('rsc:host-manifest', ['--print' => true]);

    expect(Artisan::output())->toContain('"sources": {');
});
