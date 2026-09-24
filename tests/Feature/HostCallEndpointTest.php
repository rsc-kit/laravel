<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Validation\ValidationException;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallDispatcher;
use RscKit\Revalidation;
use RscKit\RscRedirectException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
        config()->set('app.debug', true);
        $dispatcher = dispatcherWith(['Orders.recent' => fn () => null]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.recnt', 'args' => []]);

        expect($answer['status'])->toBe(404);
        expect($answer['reply']['error'])->toContain('Orders.recnt');
        expect($answer['reply']['error'])->toContain('Orders.recent');
    });

    it('keeps the list to itself in production', function () {
        // The list is the application's whole callable surface, and whoever
        // guessed a name wrong may be a stranger.
        config()->set('app.debug', false);
        $dispatcher = dispatcherWith(['Orders.recent' => fn () => null, 'Admin.purge' => fn () => null]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.recnt', 'args' => []]);

        expect($answer['status'])->toBe(404);
        expect($answer['reply']['error'])->toContain('Orders.recnt');
        expect($answer['reply']['error'])->not->toContain('Orders.recent');
        expect($answer['reply']['error'])->not->toContain('Admin.purge');
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
        config()->set('app.debug', true);

        $dispatcher = dispatcherWith([
            'Orders.recent' => fn () => throw new RuntimeException('orders table is missing'),
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.recent', 'args' => []]);

        expect($answer['status'])->toBe(500);
        expect($answer['reply']['error'])->toContain('orders table is missing');
        expect($answer['reply'])->not->toHaveKey('validationErrors');
    });

    it('keeps the message to itself in production, and reports the failure', function () {
        // The endpoint answers a failure itself, so Laravel's handler never
        // saw one and nothing reached the log. And the message - a query and
        // its bindings, a path on the server - went to the renderer, which
        // puts it where a visitor can read it.
        Exceptions::fake();
        config()->set('app.debug', false);

        $dispatcher = dispatcherWith([
            'Orders.recent' => fn () => throw new RuntimeException('SQLSTATE[42S02]: orders table is missing'),
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Orders.recent', 'args' => []]);

        expect($answer['status'])->toBe(500);
        expect($answer['reply']['error'])->toBe('Server Error');
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'orders table is missing'));
    });

    it('reports a failure with debug on too', function () {
        Exceptions::fake();
        config()->set('app.debug', true);

        dispatcherWith(['Orders.recent' => fn () => throw new RuntimeException('boom')])
            ->dispatch(['function' => 'Orders.recent', 'args' => []]);

        Exceptions::assertReported(RuntimeException::class);
    });

    it('does not report a refusal, which is an answer rather than a failure', function () {
        Exceptions::fake();

        $dispatcher = dispatcherWith([
            'a' => fn () => throw ValidationException::withMessages(['name' => ['Required.']]),
            'b' => fn () => throw new AuthenticationException,
            'c' => fn () => throw new AuthorizationException,
            'd' => fn () => throw new RscRedirectException('/login'),
            'e' => fn () => abort(429),
        ]);

        foreach (['a', 'b', 'c', 'd', 'e'] as $name) {
            $dispatcher->dispatch(['function' => $name, 'args' => []]);
        }

        Exceptions::assertNothingReported();
    });

    it('reports an abort that means the server failed', function () {
        // Handed to report() like any failure, and Laravel's own policy then
        // decides: it ignores HttpExceptions unless the application asks it
        // not to, which is the application's call to make, not this one's.
        app(ExceptionHandler::class)->stopIgnoring(HttpException::class);
        Exceptions::fake();

        $answer = dispatcherWith(['a' => fn () => abort(503, 'Down for maintenance.')])
            ->dispatch(['function' => 'a', 'args' => []]);

        expect($answer['status'])->toBe(503);
        Exceptions::assertReported(HttpException::class);
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

    it('carries the redirect\'s status, which the engine would otherwise make a 307', function () {
        // A 307 replays a POST at the destination. A middleware that
        // redirected with a 302 or a 303 meant the browser to arrive with a GET.
        $dispatcher = dispatcherWith([
            'Session.start' => fn () => throw new RscRedirectException('/login', 303),
        ]);

        $answer = $dispatcher->dispatch(['function' => 'Session.start', 'args' => []]);

        expect($answer['reply']['redirectStatus'])->toBe(303);
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

    it('does not carry the marks of a call that threw into the next', function () {
        // Taken only on success, a mark made before the throw stayed behind,
        // and the next call in the batch answered with it as its own.
        $dispatcher = dispatcherWith([
            'Orders.create' => function () {
                app(Revalidation::class)->mark('orders');

                throw new RuntimeException('then failed');
            },
            'Orders.recent' => fn () => [],
        ]);

        $answer = $dispatcher->dispatch(['calls' => [
            ['function' => 'Orders.create', 'args' => []],
            ['function' => 'Orders.recent', 'args' => []],
        ]]);

        expect($answer['reply']['replies'][0]['status'])->toBe(500);
        expect($answer['reply']['replies'][1])->not->toHaveKey('revalidate');
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

describe('a batch', function () {
    // Several calls the renderer issued in one tick of a render, answered in
    // order. One Laravel request for a page's parallel reads rather than one
    // each - and every call still answers as itself.
    it('answers every call in order, each with the status it would have had alone', function () {
        $dispatcher = dispatcherWith([
            'Orders.recent' => fn (int $limit) => array_fill(0, $limit, 'order'),
            'Orders.create' => fn () => throw ValidationException::withMessages(['name' => ['Taken.']]),
            'Me.session' => fn () => throw new AuthenticationException,
        ]);

        $answer = $dispatcher->dispatch(['calls' => [
            ['function' => 'Orders.recent', 'args' => [2]],
            ['function' => 'Orders.create', 'args' => []],
            ['function' => 'Me.session', 'args' => []],
            ['function' => 'Nope', 'args' => []],
        ]]);

        expect($answer['status'])->toBe(200);

        $replies = $answer['reply']['replies'];

        expect($replies)->toHaveCount(4);
        expect($replies[0])->toMatchArray(['status' => 200, 'result' => ['order', 'order']]);
        expect($replies[1]['status'])->toBe(422);
        expect($replies[1]['validationErrors'])->toBe(['name' => ['Taken.']]);
        expect($replies[2])->toMatchArray(['status' => 401, 'unauthenticated' => true]);
        expect($replies[3]['status'])->toBe(404);
    });

    it('keeps each call\'s revalidation with that call', function () {
        $dispatcher = dispatcherWith([
            'Orders.create' => function () {
                app(Revalidation::class)->mark('orders');

                return null;
            },
            'Orders.recent' => fn () => [],
        ]);

        $answer = $dispatcher->dispatch(['calls' => [
            ['function' => 'Orders.create', 'args' => []],
            ['function' => 'Orders.recent', 'args' => []],
        ]]);

        expect($answer['reply']['replies'][0]['revalidate'])->toBe(['orders']);
        expect($answer['reply']['replies'][1])->not->toHaveKey('revalidate');
    });

    it('refuses an envelope that is not a list of calls', function () {
        $dispatcher = dispatcherWith([]);

        expect($dispatcher->dispatch(['calls' => []])['status'])->toBe(400);
        expect($dispatcher->dispatch(['calls' => 'Orders.recent'])['status'])->toBe(400);
        // A call inside that is not an object is that call's 400, not the batch's.
        expect($dispatcher->dispatch(['calls' => ['x']])['reply']['replies'][0]['status'])->toBe(400);
    });

    it('refuses more calls than the engine ever sends in one', function () {
        // Without a ceiling one request holds a worker for as many calls as
        // it cares to list. The engine's own limit is 50.
        $dispatcher = dispatcherWith(['Orders.recent' => fn () => []]);
        $call = ['function' => 'Orders.recent', 'args' => []];

        expect($dispatcher->dispatch(['calls' => array_fill(0, 50, $call)])['status'])->toBe(200);
        expect($dispatcher->dispatch(['calls' => array_fill(0, 51, $call)])['status'])->toBe(413);
        expect($dispatcher->batchRefusal(['calls' => array_fill(0, 51, $call)])['status'])->toBe(413);
    });

    it('answers a result it cannot encode as that call\'s failure, and still answers the rest', function () {
        // Encoded after the headers had gone, it threw, the stream ended, and
        // every call after it went unanswered.
        Exceptions::fake();

        $dispatcher = dispatcherWith([
            'Stats.ratio' => fn () => INF,
            'Orders.recent' => fn () => ['ok'],
        ]);

        $lines = array_map(
            fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            iterator_to_array($dispatcher->batch([
                ['function' => 'Stats.ratio', 'args' => []],
                ['function' => 'Orders.recent', 'args' => []],
            ])),
        );

        expect($lines)->toHaveCount(2);
        expect($lines[0])->toMatchArray(['index' => 0, 'status' => 500]);
        expect($lines[0])->not->toHaveKey('result');
        expect($lines[1])->toBe(['index' => 1, 'status' => 200, 'result' => ['ok']]);
        Exceptions::assertReported(JsonException::class);
    });

    it('encodes a result as a controller returning it would have', function () {
        // A value that is only Arrayable went out as its public properties -
        // on both paths, since neither looked past the reply around it.
        $dispatcher = dispatcherWith(['Orders.summary' => fn () => new OnlyArrayable]);

        $single = json_decode($dispatcher->respond(['function' => 'Orders.summary', 'args' => []])['json'], true);
        $line = json_decode(iterator_to_array($dispatcher->batch([['function' => 'Orders.summary', 'args' => []]]))[0], true);

        expect($single['result'])->toBe(['total' => 3]);
        expect($line['result'])->toBe(['total' => 3]);
    });
});

class OnlyArrayable implements Arrayable
{
    public string $internal = 'not for the wire';

    public function toArray(): array
    {
        return ['total' => 3];
    }
}

describe('a single answer', function () {
    it('answers a result it cannot encode as a 500, not an exception', function () {
        // JsonResponse threw on it, after the call had already run, and
        // Laravel's handler answered with a page rather than this contract.
        Exceptions::fake();
        config()->set('app.debug', false);

        $answer = dispatcherWith(['Stats.ratio' => fn () => NAN])
            ->respond(['function' => 'Stats.ratio', 'args' => []]);

        expect($answer['status'])->toBe(500);
        expect(json_decode($answer['json'], true))->toBe(['error' => 'Server Error']);
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
