<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use RscKit\Attributes\Authenticated;
use RscKit\Attributes\Can;
use RscKit\CallableRegistry;
use RscTestClosureMiddleware\ClosureMiddlewareActions;

#[Authenticated]
class AuthenticatedActions
{
    public function doSomething(): string
    {
        return 'done';
    }
}

class MethodLevelAuthActions
{
    #[Authenticated]
    public function secured(): string
    {
        return 'secured';
    }

    public function open(): string
    {
        return 'open';
    }
}

#[Can('admin')]
class GatedActions
{
    public function reset(): string
    {
        return 'reset';
    }
}

class MethodGatedActions
{
    #[Can('delete', 'App\Models\Todo')]
    public function remove(): string
    {
        return 'removed';
    }
}

#[Authenticated]
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

test('class-level Authenticated throws when unauthenticated', function () {
    Auth::shouldReceive('guard')->with(null)->andReturnSelf();
    Auth::shouldReceive('check')->andReturn(false);

    $registry = freshRegistry();
    $registry->register('AuthenticatedActions.doSomething', [AuthenticatedActions::class, 'doSomething']);

    $registry->execute('AuthenticatedActions.doSomething', []);
})->throws(AuthenticationException::class);

test('class-level Authenticated passes when authenticated', function () {
    Auth::shouldReceive('guard')->with(null)->andReturnSelf();
    Auth::shouldReceive('check')->andReturn(true);

    $registry = freshRegistry();
    $registry->register('AuthenticatedActions.doSomething', [AuthenticatedActions::class, 'doSomething']);

    $result = $registry->execute('AuthenticatedActions.doSomething', []);

    expect($result)->toBe('done');
});

test('method-level Authenticated throws when unauthenticated', function () {
    Auth::shouldReceive('guard')->with(null)->andReturnSelf();
    Auth::shouldReceive('check')->andReturn(false);

    $registry = freshRegistry();
    $registry->register('MethodLevelAuthActions.secured', [MethodLevelAuthActions::class, 'secured']);

    $registry->execute('MethodLevelAuthActions.secured', []);
})->throws(AuthenticationException::class);

test('method without Authenticated attribute does not require auth', function () {
    $registry = freshRegistry();
    $registry->register('MethodLevelAuthActions.open', [MethodLevelAuthActions::class, 'open']);

    $result = $registry->execute('MethodLevelAuthActions.open', []);

    expect($result)->toBe('open');
});

test('class-level Can throws AuthorizationException when unauthorized', function () {
    Gate::shouldReceive('authorize')
        ->with('admin', [])
        ->andThrow(new AuthorizationException('This action is unauthorized.'));

    $registry = freshRegistry();
    $registry->register('GatedActions.reset', [GatedActions::class, 'reset']);

    $registry->execute('GatedActions.reset', []);
})->throws(AuthorizationException::class);

test('method-level Can with model throws AuthorizationException when unauthorized', function () {
    Gate::shouldReceive('authorize')
        ->with('delete', ['App\Models\Todo'])
        ->andThrow(new AuthorizationException('This action is unauthorized.'));

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
