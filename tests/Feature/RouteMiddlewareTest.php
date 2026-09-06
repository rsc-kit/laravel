<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallDispatcher;
use RscKit\Revalidation;
use RscKit\RouteMiddleware;
use RscKit\RscRedirectException;

/**
 * Middleware for a route the engine owns.
 *
 * This is what route.php's middleware() and can() become: the names travel in
 * the manifest, the engine asks before anything at or below the route renders,
 * and Laravel runs them through the real pipeline against the real request.
 *
 * What is pinned here is that a refusal is a refusal. The engine treats
 * anything that is not a literal true as one, so the job on this side is to
 * never return true when a middleware stopped the request — and to let the
 * reason travel, so an unauthenticated visitor reaches the login page instead
 * of a failed render.
 */
function runner(): RouteMiddleware
{
    return new RouteMiddleware(app());
}

it('allows a route with no middleware', function () {
    expect(runner()->run([]))->toBeTrue();
});

it('runs a middleware and allows when it passes', function () {
    app('router')->aliasMiddleware('waves', function (Request $request, Closure $next) {
        return $next($request);
    });

    expect(runner()->run(['waves']))->toBeTrue();
});

it('runs them in the order given, outermost first', function () {
    $seen = [];

    app('router')->aliasMiddleware('outer', function ($request, Closure $next) use (&$seen) {
        $seen[] = 'outer';

        return $next($request);
    });
    app('router')->aliasMiddleware('inner', function ($request, Closure $next) use (&$seen) {
        $seen[] = 'inner';

        return $next($request);
    });

    runner()->run(['outer', 'inner']);

    expect($seen)->toBe(['outer', 'inner']);
});

it('does not reach an inner middleware when an outer one refuses', function () {
    $reached = false;

    app('router')->aliasMiddleware('refuses', function () {
        throw new AuthorizationException('nope');
    });
    app('router')->aliasMiddleware('records', function ($request, Closure $next) use (&$reached) {
        $reached = true;

        return $next($request);
    });

    expect(fn () => runner()->run(['refuses', 'records']))->toThrow(AuthorizationException::class);
    expect($reached)->toBeFalse();
});

it('never returns true when a middleware stopped the request', function () {
    // The one thing this side owes the engine. Returning true after a
    // middleware aborted would render a guarded page to whoever asked.
    app('router')->aliasMiddleware('aborts', function () {
        throw new AuthorizationException('This action is unauthorized.');
    });

    expect(fn () => runner()->run(['aborts']))->toThrow(AuthorizationException::class);
});

it('sends an unauthenticated visitor to the login route', function () {
    // Laravel's own behaviour, supplied by its exception handler on an
    // ordinary route and by nothing here. Without it an unauthenticated
    // visitor gets a failed render rather than the login page.
    Route::get('/login', fn () => 'login')->name('login');

    app('router')->aliasMiddleware('needs-auth', function () {
        throw new AuthenticationException;
    });

    expect(fn () => runner()->run(['needs-auth']))
        ->toThrow(RscRedirectException::class);
});

it('lets the exception stand when there is nowhere to send them', function () {
    // An API-only application should answer 401, not redirect to a page it
    // does not have.
    app('router')->aliasMiddleware('needs-auth', function () {
        throw new AuthenticationException;
    });

    expect(fn () => runner()->run(['needs-auth']))->toThrow(AuthenticationException::class);
});

it('carries middleware arguments through, commas and all', function () {
    // A class rather than a closure alias: Laravel's resolver appends
    // ":args" to whatever the alias maps to, and a Closure cannot be
    // concatenated with a string. throttle:60,1 is the case that matters, and
    // it only works through a real middleware.
    app('router')->aliasMiddleware('records-args', RecordsArgs::class);

    RecordsArgs::$seen = null;

    runner()->run(['records-args:60,1']);

    expect(RecordsArgs::$seen)->toBe(['60', '1']);
});

describe('through the endpoint', function () {
    it('answers the engine with true when the route may render', function () {
        app('router')->aliasMiddleware('waves', fn ($request, Closure $next) => $next($request));

        $registry = new CallableRegistry(app());
        $registry->register(RouteMiddleware::FUNCTION, fn (array $names = []) => runner()->run($names));

        $answer = (new HostCallDispatcher($registry, app(Revalidation::class), 's'))
            ->dispatch(['function' => RouteMiddleware::FUNCTION, 'args' => [['waves']]]);

        expect($answer['status'])->toBe(200);
        expect($answer['reply']['result'])->toBeTrue();
    });

    it('answers a refusal as a refusal, never as a result', function () {
        app('router')->aliasMiddleware('refuses', function () {
            throw new AuthorizationException('This action is unauthorized.');
        });

        $registry = new CallableRegistry(app());
        $registry->register(RouteMiddleware::FUNCTION, fn (array $names = []) => runner()->run($names));

        $answer = (new HostCallDispatcher($registry, app(Revalidation::class), 's'))
            ->dispatch(['function' => RouteMiddleware::FUNCTION, 'args' => [['refuses']]]);

        expect($answer['status'])->toBe(403);
        expect($answer['reply'])->not->toHaveKey('result');
    });
});

class RecordsArgs
{
    public static ?array $seen = null;

    public function handle($request, Closure $next, ...$args)
    {
        self::$seen = $args;

        return $next($request);
    }
}
