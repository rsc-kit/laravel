<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Routing\Attributes\Controllers\WithoutMiddleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use JetBrains\PhpStorm\Pure;
use RscKit\Attributes\Authenticated;
use RscKit\Attributes\Can;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallDispatcher;
use RscKit\Revalidation;

/**
 * Laravel's own attributes guard a callable as they guard a controller.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class OwnersOnly extends Middleware
{
    public function __construct()
    {
        parent::__construct('can:own');
    }
}

class LaravelGuarded
{
    #[Authorize('own')]
    public function authorized(): string
    {
        return 'ran';
    }

    #[Authorize('cancel', 'App\Models\Order')]
    public function model(): string
    {
        return 'ran';
    }

    #[OwnersOnly]
    public function ownAttribute(): string
    {
        return 'ran';
    }

    #[Middleware('auth')]
    public function signedIn(): string
    {
        return 'ran';
    }

    // Written last to first: auth has to choose the guard before the policy.
    #[Authorize('own')]
    #[Middleware('auth:admin')]
    public function admin(): mixed
    {
        return auth()->user()->getAuthIdentifier();
    }
}

#[Authorize('own')]
abstract class AuthorizedBase
{
    public function inherited(): string
    {
        return 'ran';
    }
}

class AuthorizedChild extends AuthorizedBase {}

#[Middleware('auth', only: ['store'])]
#[Middleware('refuses', except: ['index', 'store', 'free'])]
class PartlyGuarded
{
    public function index(): string
    {
        return 'ran';
    }

    public function store(): string
    {
        return 'ran';
    }

    public function destroy(): string
    {
        return 'ran';
    }
}

#[Middleware('auth')]
class GuardedButOne
{
    #[WithoutMiddleware('auth')]
    public function open(): string
    {
        return 'ran';
    }

    #[WithoutMiddleware('throttle')]
    public function closed(): string
    {
        return 'ran';
    }
}

class GuardsThatAreNotThere
{
    #[Authenticated]
    public function authenticated(): string
    {
        return 'ran';
    }

    #[Can('own')]
    public function can(): string
    {
        return 'ran';
    }

    #[App\Rsc\Actions\Middleware('auth')]
    public function forgottenUse(): string
    {
        return 'ran';
    }

    #[Illuminate\Routing\Controllers\Middleware('auth')]
    public function notAnAttribute(): string
    {
        return 'ran';
    }

    #[Illuminate\Container\Attributes\Authenticated]
    public function forParameters(): string
    {
        return 'ran';
    }

    #[Pure]
    public function notAGuard(): string
    {
        return 'ran';
    }
}

#[App\Rsc\Actions\Authorize('own')]
abstract class MissingOnTheParent
{
    public function inherited(): string
    {
        return 'ran';
    }
}

class MissingOnTheChild extends MissingOnTheParent {}

function guarded(string $class, string $method): array
{
    $registry = new CallableRegistry(app());
    $registry->register('it', [$class, $method]);

    return (new HostCallDispatcher($registry, app(Revalidation::class), 's3cret'))->dispatch(['function' => 'it', 'args' => []]);
}

function signIn(int $id, ?string $guard = null): void
{
    auth()->guard($guard)->setUser((new User)->forceFill(['id' => $id]));
}

beforeEach(function () {
    app(Kernel::class);
    app()->instance('request', Request::create('/__rsc/host-call', 'POST'));
    app('router')->aliasMiddleware('refuses', fn () => abort(418, 'No.'));
    Gate::define('own', fn (User $user) => $user->id === 1);
    Gate::define('cancel', fn (User $user, string $model) => $user->id === 1 && $model === 'App\Models\Order');
});

it('refuses what extends #[Middleware], rather than read past it', function (string $class, string $method) {
    expect(guarded($class, $method)['status'])->toBe(403);

    signIn(2);
    expect(guarded($class, $method)['status'])->toBe(403);

    signIn(1);
    expect(guarded($class, $method)['reply'])->toBe(['result' => 'ran']);
})->with([
    '#[Authorize]' => [LaravelGuarded::class, 'authorized'],
    '#[Authorize] on a model' => [LaravelGuarded::class, 'model'],
    '#[Authorize] on a parent' => [AuthorizedChild::class, 'inherited'],
    'an app\'s own' => [LaravelGuarded::class, 'ownAttribute'],
]);

it('answers a guest 401, with a login route or none', function () {
    expect(guarded(LaravelGuarded::class, 'signedIn'))
        ->toBe(['status' => 401, 'reply' => ['unauthenticated' => true, 'error' => 'Unauthenticated.']]);

    Route::get('/login', fn () => '')->name('login');
    Route::getRoutes()->refreshNameLookups();

    expect(guarded(LaravelGuarded::class, 'signedIn')['status'])->toBe(401);
});

it('asks the policy as the guard the middleware chose', function () {
    config(['auth.guards.admin' => ['driver' => 'session', 'provider' => 'users']]);
    signIn(1, 'admin');

    expect(guarded(LaravelGuarded::class, 'admin')['reply'])->toBe(['result' => 1]);
});

it('keeps to only and except', function () {
    expect(guarded(PartlyGuarded::class, 'index')['reply'])->toBe(['result' => 'ran']);
    expect(guarded(PartlyGuarded::class, 'store')['status'])->toBe(401);
    expect(guarded(PartlyGuarded::class, 'destroy')['status'])->toBe(418);
});

it('goes without only what #[WithoutMiddleware] names', function () {
    expect(guarded(GuardedButOne::class, 'open')['reply'])->toBe(['result' => 'ran']);
    expect(guarded(GuardedButOne::class, 'closed')['status'])->toBe(401);
});

it('names the attribute the build cannot answer past', function () {
    $registry = new CallableRegistry(app());
    $registry->register('a', [LaravelGuarded::class, 'authorized']);
    $registry->register('b', [LaravelGuarded::class, 'signedIn']);

    expect($registry->asksFor('a'))->toBe('#[Authorize]')->and($registry->asksFor('b'))->toBe('#[Middleware]');
});

it('refuses a guard PHP cannot make, rather than run open', function (string $class, string $method) {
    signIn(1);

    expect(guarded($class, $method)['status'])->toBe(500);

    $registry = new CallableRegistry(app());
    $registry->register('it', [$class, $method]);

    expect(fn () => $registry->execute('it', []))->toThrow(LogicException::class, 'which guards nothing');
    expect(fn () => $registry->asksFor('it'))->toThrow(LogicException::class);
})->with([
    [GuardsThatAreNotThere::class, 'authenticated'],
    [GuardsThatAreNotThere::class, 'can'],
    [GuardsThatAreNotThere::class, 'forgottenUse'],
    [GuardsThatAreNotThere::class, 'notAnAttribute'],
    [GuardsThatAreNotThere::class, 'forParameters'],
    [MissingOnTheChild::class, 'inherited'],
]);

it('leaves any other attribute alone', function () {
    expect(guarded(GuardsThatAreNotThere::class, 'notAGuard')['reply'])->toBe(['result' => 'ran']);
});

it('fails the manifest on one, so dev and the build say so first', function () {
    app(CallableRegistry::class)->register('it', [GuardsThatAreNotThere::class, 'forgottenUse']);

    $this->artisan('rsc:host-manifest', ['--print' => true])->assertFailed();

    expect(CallableRegistry::deadGuards([
        'a' => [GuardsThatAreNotThere::class, 'forgottenUse'],
        'b' => [MissingOnTheChild::class, 'inherited'],
        'c' => [GuardsThatAreNotThere::class, 'notAGuard'],
        'd' => fn () => 1,
    ]))->toHaveCount(2)->sequence(
        fn ($message) => $message->toContain('GuardsThatAreNotThere::forgottenUse() is guarded by #[App\Rsc\Actions\Middleware]'),
        fn ($message) => $message->toContain('MissingOnTheParent is guarded by'),
    );
});
