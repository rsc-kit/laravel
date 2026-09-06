<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallDispatcher;
use RscKit\Revalidation;
use RscKit\RscRedirectException;

/**
 * Answering a host call over HTTP.
 *
 * The transport the engine is moving to. What matters is not that it works —
 * it is that it means the same things the callback socket already means: a
 * refusal is not a failure, a redirect is not a 3xx, and an unknown name is
 * this side's to report because the caller cannot know what was registered.
 *
 * Everything here goes through the dispatcher rather than the controller,
 * because the controller deliberately decides nothing: it reads a header,
 * decodes a body and hands both over. A second framework's binding would be
 * the same twenty lines, and these are the assertions it would inherit.
 */
function dispatcherWith(array $callables, string $secret = 's3cret'): HostCallDispatcher
{
    $registry = new CallableRegistry(app());

    foreach ($callables as $name => $callable) {
        $registry->register($name, $callable);
    }

    return new HostCallDispatcher($registry, app(Revalidation::class), $secret);
}

describe('the secret', function () {
    it('accepts the configured one', function () {
        expect(dispatcherWith([])->authorises('s3cret'))->toBeTrue();
    });

    it('refuses a wrong one, a missing one, and an empty one', function () {
        $dispatcher = dispatcherWith([]);

        expect($dispatcher->authorises('nope'))->toBeFalse();
        expect($dispatcher->authorises(null))->toBeFalse();
        expect($dispatcher->authorises(''))->toBeFalse();
    });

    it('refuses everything when none is configured', function () {
        // A host that has not opted in must not answer, rather than answering
        // to whoever guesses the empty string.
        $dispatcher = dispatcherWith([], secret: '');

        expect($dispatcher->authorises(''))->toBeFalse();
        expect($dispatcher->authorises(null))->toBeFalse();
    });
});

describe('dispatching', function () {
    it('runs the named function and returns its result', function () {
        $dispatcher = dispatcherWith([
            'Orders.recent' => fn (int $limit) => array_map(fn ($i) => ['id' => $i], range(1, $limit)),
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.recent', 'args' => [3]]);

        expect($answer['status'])->toBe(200);
        expect($answer['reply']['result'])->toHaveCount(3);
    });

    it('names an unknown function and lists what exists', function () {
        // The caller cannot say which function is missing — it does not know
        // what this host registered — so this side has to.
        $dispatcher = dispatcherWith(['Orders.recent' => fn () => null]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.recnt', 'args' => []]);

        expect($answer['status'])->toBe(404);
        expect($answer['reply']['error'])->toContain('Orders.recnt');
        expect($answer['reply']['error'])->toContain('Orders.recent');
    });

    it('rejects a body with no function name', function () {
        expect(dispatcherWith([])->dispatch(['args' => []])['status'])->toBe(400);
        expect(dispatcherWith([])->dispatch(null)['status'])->toBe(400);
    });

    it('rejects args that are not a list', function () {
        $answer = dispatcherWith(['X.y' => fn () => null])
            ->dispatch(['function' => 'X.y', 'args' => 'not-a-list']);

        expect($answer['status'])->toBe(400);
    });
});

describe('what a failure means', function () {
    it('reports a thrown error as a failure, with its message', function () {
        $dispatcher = dispatcherWith([
            'Orders.recent' => fn () => throw new RuntimeException('orders table is missing'),
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.recent', 'args' => []]);

        expect($answer['status'])->toBe(500);
        expect($answer['reply']['error'])->toContain('orders table is missing');
        expect($answer['reply'])->not->toHaveKey('validationErrors');
    });

    it('answers a refusal with its fields, not as a failure', function () {
        // The distinction the whole contract turns on: one becomes messages
        // under the inputs, the other becomes a 500 nobody should be able to
        // cause. They travel in separate fields so neither side has to read a
        // message to tell them apart.
        $dispatcher = dispatcherWith([
            'Orders.create' => fn () => throw ValidationException::withMessages([
                'name' => ['The name field is required.'],
                'quantity' => ['The quantity must be a number.', 'The quantity must be at least 1.'],
            ]),
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.create', 'args' => []]);

        expect($answer['status'])->toBe(422);
        expect($answer['reply']['validationErrors']['name'])->toBe(['The name field is required.']);
        expect($answer['reply']['validationErrors']['quantity'])->toHaveCount(2);
    });

    it('answers an unauthenticated call with 401 and says so', function () {
        $dispatcher = dispatcherWith([
            'Me.orders' => fn () => throw new AuthenticationException,
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Me.orders', 'args' => []]);

        expect($answer['status'])->toBe(401);
        expect($answer['reply']['unauthenticated'])->toBeTrue();
    });

    it('answers an unauthorized call with 403 and says so', function () {
        $dispatcher = dispatcherWith([
            'Orders.destroy' => fn () => throw new AuthorizationException('This action is unauthorized.'),
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.destroy', 'args' => []]);

        expect($answer['status'])->toBe(403);
        expect($answer['reply']['unauthorized'])->toBeTrue();
    });

    it('carries a redirect in the body and never as a 3xx', function () {
        // An HTTP client follows a redirect transparently. A real 3xx here
        // would send the host call itself to the destination and hand whatever
        // came back to the renderer as if it were the function's result.
        $dispatcher = dispatcherWith([
            'Session.start' => fn () => throw new RscRedirectException('/login'),
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Session.start', 'args' => []]);

        expect($answer['status'])->toBe(200);
        expect($answer['status'])->toBeLessThan(300);
        expect($answer['reply']['redirect'])->toBe('/login');
    });
});

describe('revalidation', function () {
    it('carries what the function marked stale', function () {
        $dispatcher = dispatcherWith([
            'Orders.create' => function () {
                app(Revalidation::class)->mark('orders');

                return ['created' => true];
            },
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.create', 'args' => []]);

        expect($answer['reply']['revalidate'])->toBe(['orders']);
    });

    it('omits the key when nothing was marked', function () {
        // Absent rather than empty: the renderer branches on the key, and an
        // empty array that reads as truthy re-renders regions for every call
        // that changed nothing.
        $dispatcher = dispatcherWith(['Orders.recent' => fn () => []]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.recent', 'args' => []]);

        expect($answer['reply'])->not->toHaveKey('revalidate');
    });

    it('does not carry one call\'s marks into the next', function () {
        $dispatcher = dispatcherWith([
            'Orders.create' => function () {
                app(Revalidation::class)->mark('orders');

                return null;
            },
            'Orders.recent' => fn () => [],
        ]);

        $dispatcher->dispatch(['function' => 'Orders.create', 'args' => []]);
        $second = $dispatcher->dispatch(['function' => 'Orders.recent', 'args' => []]);

        expect($second['reply'])->not->toHaveKey('revalidate');
    });
});

describe('the route', function () {
    it('is not registered without a secret', function () {
        // Additive: an application that has not opted in still uses the
        // socket, and must not acquire an unauthenticated endpoint by upgrading.
        config()->set('rsc.host_call_secret', null);

        $registered = collect(app('router')->getRoutes()->getRoutes())
            ->contains(fn ($route) => $route->uri() === ltrim(config('rsc.host_call_path'), '/'));

        expect($registered)->toBeFalse();
    });
});
