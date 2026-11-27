<?php

use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\NullSessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;
use RscKit\Http\RendererProxy;

/**
 * A flash lasts one request, and a render is several.
 *
 * The renderer calls the backend once for the route's guards and again for
 * each read, every call a request of its own. Laravel spent the flash on the
 * first, and the read that showed it found nothing: Fortify's "We have emailed
 * your password reset link" never reached the page. The renderer puts one id
 * on every call of a render, and the flash is kept for each of them.
 */
const RENDER_FLASH_SESSION = 'abcdefghijklmnopqrstuvwxyz0123456789ABCD';

beforeEach(function () {
    config()->set('rsc.host_call_secret', 'flash-secret');
    config()->set('session.driver', 'array');

    Route::post('/__rsc/host-call', HostCallController::class)->middleware('web');
    Route::get('/flashes', function () {
        session()->flash('status', 'from a route');

        return 'ok';
    })->middleware('web');

    app()->forgetInstance(HostCallDispatcher::class);

    $registry = app(CallableRegistry::class);
    $registry->register('Password.email', fn () => session()->flash('status', 'We have emailed your password reset link.'));
    $registry->register('Shared.status', fn () => session('status'));
});

function callForRender(string $function, ?string $render): TestResponse
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.str_replace('-', '_', strtoupper(HostCallDispatcher::SECRET_HEADER)) => 'flash-secret',
    ];

    if ($render !== null) {
        $server['HTTP_'.str_replace('-', '_', strtoupper(HostCallDispatcher::RENDER_HEADER))] = $render;
    }

    return test()
        ->withCookie(config('session.cookie'), RENDER_FLASH_SESSION)
        ->call('POST', '/__rsc/host-call', [], [], [], $server, json_encode(['function' => $function, 'args' => []]));
}

it('hands every call of a render what the action before it flashed', function () {
    callForRender('Password.email', null)->assertOk();

    // The route's guards, then the read that shows it.
    callForRender('Shared.status', 'render-1')->assertJson(['result' => 'We have emailed your password reset link.']);
    callForRender('Shared.status', 'render-1')->assertJson(['result' => 'We have emailed your password reset link.']);
    callForRender('Shared.status', 'render-1')->assertJson(['result' => 'We have emailed your password reset link.']);
});

it('drops it at the next render', function () {
    callForRender('Password.email', null)->assertOk();
    callForRender('Shared.status', 'render-1')->assertJson(['result' => 'We have emailed your password reset link.']);

    callForRender('Shared.status', 'render-2')->assertJson(['result' => null]);
    callForRender('Shared.status', 'render-2')->assertJson(['result' => null]);
});

it('hands the next render what a route flashed in between', function () {
    callForRender('Password.email', null)->assertOk();
    callForRender('Shared.status', 'render-1')->assertJson(['result' => 'We have emailed your password reset link.']);

    test()->withCookie(config('session.cookie'), RENDER_FLASH_SESSION)->get('/flashes')->assertOk();

    callForRender('Shared.status', 'render-2')->assertJson(['result' => 'from a route']);
    callForRender('Shared.status', 'render-2')->assertJson(['result' => 'from a route']);
    callForRender('Shared.status', 'render-3')->assertJson(['result' => null]);
});

it('leaves a call without a render to Laravel, a flash for the one request after', function () {
    callForRender('Password.email', null)->assertOk();

    callForRender('Shared.status', null)->assertJson(['result' => 'We have emailed your password reset link.']);
    callForRender('Shared.status', null)->assertJson(['result' => null]);
});

/**
 * The page request through the proxy read the session before the render began
 * and saved it once the renderer's head arrived: its stale copy undid what
 * the render's calls wrote in between.
 */
function proxiedRequest(array $stored, ?string $cookie): Request
{
    $handler = new ArraySessionHandler(120);

    if ($stored !== []) {
        $handler->write(RENDER_FLASH_SESSION, serialize($stored));
    }

    $session = new Store('laravel_session', $handler, RENDER_FLASH_SESSION);
    $session->start();

    $request = Request::create('http://app.test/forgot-password', cookies: $cookie === null ? [] : ['laravel_session' => $cookie]);
    $request->setLaravelSession($session);

    return $request;
}

function leaveSession(Request $request): void
{
    (new ReflectionMethod(RendererProxy::class, 'leaveSessionToHostCalls'))->invoke(null, $request);
}

it('does not save over the session the render writes to', function () {
    $request = proxiedRequest(['status' => 'sent', '_flash' => ['old' => ['status'], 'new' => []]], RENDER_FLASH_SESSION);

    leaveSession($request);

    expect($request->session()->getHandler())->toBeInstanceOf(NullSessionHandler::class);
});

it('still saves a session that is not stored yet, whose token the XSRF cookie carries', function () {
    $request = proxiedRequest([], RENDER_FLASH_SESSION);

    leaveSession($request);

    expect($request->session()->getHandler())->toBeInstanceOf(ArraySessionHandler::class);
});

it('still saves a session the visitor did not arrive with', function () {
    $request = proxiedRequest(['status' => 'sent'], null);

    leaveSession($request);

    expect($request->session()->getHandler())->toBeInstanceOf(ArraySessionHandler::class);
});
