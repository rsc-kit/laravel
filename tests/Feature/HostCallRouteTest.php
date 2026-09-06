<?php

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
