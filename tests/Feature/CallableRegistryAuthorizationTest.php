<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Attributes\Controllers\Authorize;
use Illuminate\Routing\Attributes\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use RscKit\CallableRegistry;
use RscTestClosureMiddleware\ClosureMiddlewareActions;

#[Middleware('auth')]
class AuthenticatedActions
{
    public function doSomething(): string
    {
        return 'done';
    }
}

class MethodLevelAuthActions
{
    #[Middleware('auth')]
    public function secured(): string
    {
        return 'secured';
    }

    public function open(): string
    {
        return 'open';
    }
}

#[Authorize('admin')]
class GatedActions
{
    public function reset(): string
    {
        return 'reset';
    }
}

class MethodGatedActions
{
    #[Authorize('delete', 'App\Models\Todo')]
    public function remove(): string
    {
        return 'removed';
    }
}

#[Middleware('auth')]
abstract class GuardedBase
{
    public function wipe(): string
    {
        return 'wiped';
    }
}

class InheritsTheGuard extends GuardedBase {}

class NoAttributeActions
{
    public function ping(): string
    {
        return 'pong';
    }
}

function freshRegistry(): CallableRegistry
{
    return new CallableRegistry(app());
}

beforeEach(function () {
    app(Kernel::class);
    app()->instance('request', Request::create('/__rsc/host-call', 'POST'));
});

test('class-level auth throws when unauthenticated', function () {
    $registry = freshRegistry();
    $registry->register('AuthenticatedActions.doSomething', [AuthenticatedActions::class, 'doSomething']);

    $registry->execute('AuthenticatedActions.doSomething', []);
})->throws(AuthenticationException::class);

test('class-level auth passes when authenticated', function () {
    auth()->setUser(new User);

    $registry = freshRegistry();
    $registry->register('AuthenticatedActions.doSomething', [AuthenticatedActions::class, 'doSomething']);

    $result = $registry->execute('AuthenticatedActions.doSomething', []);

    expect($result)->toBe('done');
});

test('method-level auth throws when unauthenticated', function () {
    $registry = freshRegistry();
    $registry->register('MethodLevelAuthActions.secured', [MethodLevelAuthActions::class, 'secured']);

    $registry->execute('MethodLevelAuthActions.secured', []);
})->throws(AuthenticationException::class);

test('method without a guard does not require auth', function () {
    $registry = freshRegistry();
    $registry->register('MethodLevelAuthActions.open', [MethodLevelAuthActions::class, 'open']);

    $result = $registry->execute('MethodLevelAuthActions.open', []);

    expect($result)->toBe('open');
});

test('class-level Authorize throws AuthorizationException when unauthorized', function () {
    auth()->setUser(new User);
    Gate::define('admin', fn () => false);

    $registry = freshRegistry();
    $registry->register('GatedActions.reset', [GatedActions::class, 'reset']);

    $registry->execute('GatedActions.reset', []);
})->throws(AuthorizationException::class);

test('method-level Authorize with model throws AuthorizationException when unauthorized', function () {
    auth()->setUser(new User);
    Gate::define('delete', fn (User $user, string $model) => $model !== 'App\Models\Todo');

    $registry = freshRegistry();
    $registry->register('MethodGatedActions.remove', [MethodGatedActions::class, 'remove']);

    $registry->execute('MethodGatedActions.remove', []);
})->throws(AuthorizationException::class);

test('no attributes does not throw', function () {
    $registry = freshRegistry();
    $registry->register('NoAttributeActions.ping', [NoAttributeActions::class, 'ping']);

    $result = $registry->execute('NoAttributeActions.ping', []);

    expect($result)->toBe('pong');
});

test('closures skip authorization entirely', function () {
    $registry = freshRegistry();
    $registry->register('myClosure', fn () => 'closure-result');

    $result = $registry->execute('myClosure', []);

    expect($result)->toBe('closure-result');
});

it('guards a subclass with the attributes on its parent', function () {
    // getAttributes() reads one class. The guard on an abstract base must
    // reach the methods its children inherit - those are the ones exposed.
    $registry = freshRegistry();
    $registry->register('InheritsTheGuard.wipe', [InheritsTheGuard::class, 'wipe']);

    expect(fn () => $registry->execute('InheritsTheGuard.wipe', []))->toThrow(AuthenticationException::class);
});

describe('a closure as #[Middleware]', function () {
    beforeEach(function () {
        if (PHP_VERSION_ID < 80500) {
            $this->markTestSkipped('A closure in an attribute needs PHP 8.5.');
        }

        require_once dirname(__DIR__).'/fixtures/ClosureMiddlewareActions.php';

        app('router')->aliasMiddleware('rsc-test-passes', fn ($request, Closure $next) => $next($request));
    });

    it('runs it as the guard it is', function () {
        // Laravel's attribute is Closure|string. Read as a string, a closure
        // was a TypeError on every call it was there to guard.
        $registry = freshRegistry();
        $registry->register('refused', [ClosureMiddlewareActions::class, 'refused']);

        expect(fn () => $registry->execute('refused', []))
            ->toThrow(AuthorizationException::class, 'The closure said no.');
    });

    it('lets the call through when it passes, beside named ones', function () {
        $registry = freshRegistry();
        $registry->register('allowed', [ClosureMiddlewareActions::class, 'allowed']);

        expect($registry->execute('allowed', []))->toBe('ran');
    });
});
