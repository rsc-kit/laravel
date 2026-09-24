<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;

/**
 * The endpoint as a renderer actually reaches it.
 *
 * HostCallEndpointTest covers what the dispatcher decides. This covers the
 * wiring around it: that the secret is enforced on the way in, that a
 * malformed body is the caller's fault rather than a 500, and — the reason the
 * route sits on the 'web' group — that the visitor's forwarded cookie starts a
 * session, so a function reading auth() finds the person the page is being
 * rendered for rather than nobody.
 */
beforeEach(function () {
    config()->set('rsc.host_call_secret', 'route-secret');
    config()->set('rsc.host_call_path', '/__rsc/host-call');

    // Registered here rather than relying on the provider: the provider reads
    // config at boot, which has already happened by the time a test sets it.
    Route::post('/__rsc/host-call', HostCallController::class)->middleware('web');
});

function registerHostFunction(string $name, Closure $fn): void
{
    app()->forgetInstance(HostCallDispatcher::class);

    $registry = app(CallableRegistry::class);
    $registry->register($name, $fn);
}

function callHost(array $body, ?string $secret = 'route-secret'): TestResponse
{
    $headers = ['Content-Type' => 'application/json'];

    if ($secret !== null) {
        $headers[HostCallDispatcher::SECRET_HEADER] = $secret;
    }

    return test()->call('POST', '/__rsc/host-call', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.str_replace('-', '_', strtoupper(HostCallDispatcher::SECRET_HEADER)) => $secret ?? '',
    ], json_encode($body));
}

it('runs a function and answers with its result', function () {
    registerHostFunction('Orders.recent', fn (int $limit) => ['count' => $limit]);

    callHost(['function' => 'Orders.recent', 'args' => [4]])
        ->assertOk()
        ->assertJson(['result' => ['count' => 4]]);
});

it('refuses a call with no secret', function () {
    registerHostFunction('Orders.recent', function () {
        throw new RuntimeException('must not run');
    });

    callHost(['function' => 'Orders.recent', 'args' => []], secret: null)
        ->assertStatus(403);
});

it('refuses a call with the wrong secret', function () {
    registerHostFunction('Orders.recent', function () {
        throw new RuntimeException('must not run');
    });

    callHost(['function' => 'Orders.recent', 'args' => []], secret: 'wrong')
        ->assertStatus(403);
});

it('treats a malformed body as the caller\'s fault, not a 500', function () {
    $response = test()->call('POST', '/__rsc/host-call', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RSC_HOST_SECRET' => 'route-secret',
    ], '{"function":');

    $response->assertStatus(400);
});

it('gives the function a session bound to the request, not nobody', function () {
    // The reason the route is on the 'web' group. The renderer forwards the
    // cookie it was given, Laravel's StartSession binds a session to the
    // request, and a function asking who this is gets the person the page is
    // being rendered for.
    //
    // Asserting on request()->hasSession() specifically. Two nearby signals do
    // not discriminate: reading back a value the test seeded passes with the
    // middleware removed, because seeding and reading share this process's
    // container — and so does session()->isStarted(), because withSession()
    // starts it. Only the middleware BINDS a session to the request.
    registerHostFunction('Me.session', fn () => [
        'bound' => request()->hasSession(),
        'who' => session('who'),
    ]);

    test()->withSession(['who' => 'ramon']);

    callHost(['function' => 'Me.session', 'args' => []])
        ->assertOk()
        ->assertJson(['result' => ['bound' => true, 'who' => 'ramon']]);
});

it('answers an unknown function with 404 and names it', function () {
    $response = callHost(['function' => 'Nope.missing', 'args' => []]);

    $response->assertStatus(404);
    expect($response->json('error'))->toContain('Nope.missing');
});

describe('a batch over the wire', function () {
    // Answered as it goes: one JSON line per call, carrying its index, so the
    // renderer resolves a fast read while a slow sibling is still running.
    // The dispatcher's own tests cover what each line says; this covers the
    // shape the renderer reads.
    it('is streamed as one line per call, each with its index and status', function () {
        registerHostFunction('Orders.recent', fn (int $limit) => array_fill(0, $limit, 'order'));
        registerHostFunction('Me.session', fn () => throw new AuthenticationException);

        $response = callHost(['calls' => [
            ['function' => 'Orders.recent', 'args' => [2]],
            ['function' => 'Me.session', 'args' => []],
        ]]);

        $response->assertOk();
        expect($response->headers->get('Content-Type'))->toBe('application/x-ndjson');
        expect($response->headers->get('X-Accel-Buffering'))->toBe('no');

        $lines = array_map(
            fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", trim($response->streamedContent())),
        );

        expect($lines)->toHaveCount(2);
        expect($lines[0])->toMatchArray(['index' => 0, 'status' => 200, 'result' => ['order', 'order']]);
        expect($lines[1])->toMatchArray(['index' => 1, 'status' => 401, 'unauthenticated' => true]);
    });

    it('refuses an empty batch as one JSON answer, before streaming anything', function () {
        $response = callHost(['calls' => []]);

        $response->assertStatus(400);
        expect($response->json('error'))->toContain('non-empty');
    });

    it('refuses an oversized batch before running any of it', function () {
        registerHostFunction('Orders.recent', function () {
            throw new RuntimeException('must not run');
        });

        callHost(['calls' => array_fill(0, 51, ['function' => 'Orders.recent', 'args' => []])])
            ->assertStatus(413);
    });

    it('keeps what the calls wrote to the session', function () {
        // StartSession saves when the response leaves the middleware, and a
        // streamed response leaves before its body runs - so before any call
        // in the batch had run. Everything they wrote was dropped.
        config()->set('session.driver', 'array');

        registerHostFunction('Cart.touch', function () {
            session()->put('cart.touched', true);

            return null;
        });

        callHost(['calls' => [['function' => 'Cart.touch', 'args' => []]]])->streamedContent();

        // Read back from the handler - what the next request would load -
        // rather than from the store still in memory, which has it either way.
        $session = app('session')->driver();
        $stored = unserialize($session->getHandler()->read($session->getId()));

        expect($stored['cart']['touched'] ?? null)->toBeTrue();
    });

    it('gives each call a request of its own, holding only its own input', function () {
        // The request the endpoint received is the host call's, so a form
        // request built from it saw "function", "args" and "calls" beside
        // its fields - and with every call's args merged into that one
        // request, the second call validated with what the first had sent.
        app(CallableRegistry::class)->register('Orders.store', StoresOrder::class);
        app()->forgetInstance(HostCallDispatcher::class);

        $response = callHost(['calls' => [
            ['function' => 'Orders.store', 'args' => [['name' => 'first', 'note' => 'from the first call']]],
            ['function' => 'Orders.store', 'args' => [[]]],
        ]]);

        $lines = array_map(
            fn (string $line) => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            explode("\n", trim($response->streamedContent())),
        );

        expect($lines[0])->toMatchArray(['status' => 200, 'result' => ['name' => 'first', 'note' => 'from the first call']]);
        expect($lines[1]['status'])->toBe(422);
        expect($lines[1]['validationErrors'])->toHaveKey('name');
    });
});

it('answers a result it cannot encode as a failure in the contract\'s own shape', function () {
    // JsonResponse threw on it once the call had run, and Laravel's handler
    // answered with its own error page rather than {"error": ...}.
    config()->set('app.debug', false);
    registerHostFunction('Stats.ratio', fn () => INF);

    $response = callHost(['function' => 'Stats.ratio', 'args' => []]);

    $response->assertStatus(500);
    expect($response->json())->toBe(['error' => 'Server Error']);
});

it('closes every buffer between a batch line and the socket', function () {
    // Flushing the top buffer only hands its contents to the one below, and
    // under FPM php.ini's output_buffering is usually that one: a fast read
    // waited there until 4KB of answers had piled up behind it.
    ob_start();
    $floor = ob_get_level();

    ob_start();
    ob_start();
    echo 'a line';

    HostCallController::drainOutputBuffers('fpm-fcgi', $floor);

    expect(ob_get_level())->toBe($floor);
    expect(ob_get_clean())->toBe('a line');
});

it('leaves the buffers alone under the CLI, where they are somebody else\'s', function () {
    // Octane's Swoole and RoadRunner workers capture a response by
    // buffering it; closing their buffer sends the body nowhere.
    ob_start();
    $level = ob_get_level();

    HostCallController::drainOutputBuffers('cli');

    expect(ob_get_level())->toBe($level);
    ob_end_clean();
});

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string']];
    }
}

class StoresOrder
{
    public function __invoke(StoreOrderRequest $request): array
    {
        return $request->all();
    }
}
