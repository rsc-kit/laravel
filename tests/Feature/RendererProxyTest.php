<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RscKit\Http\HostCallController;
use RscKit\Http\RendererProxy;
use RscKit\ProxyWouldDeadlockException;
use RscKit\RendererNotRunningException;

/**
 * Handing a request Laravel does not route to the renderer.
 *
 * What matters here is WHEN it happens, not how. The proxy costs a PHP worker
 * for the length of a render while the renderer calls back into this same
 * application for data — so with W workers it caps concurrent renders at W-1,
 * and if every worker is blocked proxying, those calls have nobody to answer
 * them. That is fine for one developer and wrong for a busy deployment, which
 * is why it follows the dev server rather than a default url.
 */
beforeEach(function () {
    config()->set('rsc.hot_file', sys_get_temp_dir().'/rsc-hot-'.getmypid());
    config()->set('rsc.renderer_url', null);

    @unlink(config('rsc.hot_file'));
});

afterEach(fn () => @unlink(config('rsc.hot_file')));

it('says what to run when a developer hits a page and nothing is rendering', function () {
    // A 404 here would say the route does not exist, which is untrue and the
    // hardest possible thing to act on: the route is fine, the renderer is not
    // running.
    $this->withoutExceptionHandling();
    config()->set('app.debug', true);

    try {
        $this->get('/a-page-with-no-route');
        $this->fail('expected to be told the renderer is not running');
    } catch (RendererNotRunningException $e) {
        expect($e->getMessage())->toContain('npm run dev');
        expect($e->getMessage())->toContain('RSC_RENDERER_URL');
    }
});

it('is a plain 404 in production, where the renderer is in front', function () {
    // Page requests never reach Laravel in that shape, so whatever arrives
    // here genuinely has no route — a bot, a stale link, a typo. Setup
    // instructions would be useless to them and would tell a stranger how the
    // application is wired.
    config()->set('app.debug', false);

    $this->get('/a-page-with-no-route')->assertNotFound();
});

it('follows the dev server while one is running', function () {
    config()->set('app.debug', false);
    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:65535');

    // Nothing is listening there, so this proves only that it TRIED — which is
    // the thing being asserted: the hot file turned the proxy on.
    $this->get('/a-page-with-no-route')->assertStatus(502);
});

it('stops following it the moment the dev server goes away', function () {
    config()->set('app.debug', false);
    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:65535');
    $this->get('/a-page-with-no-route')->assertStatus(502);

    // The dev server removes the file on shutdown, and the proxy has to notice
    // per request — reading it once at boot would leave an app that outlived
    // its dev server proxying into nothing.
    @unlink(config('rsc.hot_file'));

    $this->get('/a-page-with-no-route')->assertNotFound();
});

it('uses a configured url when there is no dev server', function () {
    config()->set('app.debug', false);
    config()->set('rsc.renderer_url', 'http://127.0.0.1:65535');

    $this->get('/a-page-with-no-route')->assertStatus(502);
});

it('prefers the dev server over a configured url', function () {
    // A developer with RSC_RENDERER_URL set for their deployment should still
    // get the dev server they just started, not the production one.
    $this->withoutExceptionHandling();

    config()->set('rsc.renderer_url', 'http://configured.invalid');
    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:65535');

    try {
        $this->get('/a-page-with-no-route');
        $this->fail('expected the renderer to be reported as not running');
    } catch (RendererNotRunningException $e) {
        // The dev server's port, not the configured host — the file wins.
        expect($e->url())->toContain('65535');
    }
});

it('never takes a request a real route already answers', function () {
    config()->set('app.debug', false);
    Route::get('/answered-here', fn () => 'mine');

    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:65535');

    $this->get('/answered-here')->assertOk()->assertSee('mine');
});

it('leaves the host-call endpoint alone', function () {
    // The renderer posts here. If the fallback swallowed it, the renderer would
    // be talking to itself and every rpc() would fail.
    //
    // Registered here rather than relying on the provider, which reads the
    // secret at boot — by the time a test sets one, that has happened.
    config()->set('app.debug', false);
    config()->set('rsc.host_call_secret', 'secret');
    Route::post(config('rsc.host_call_path'), HostCallController::class);

    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:65535');

    // 403 is the endpoint refusing an unsigned call. Anything else means the
    // fallback took a request that was never its to take.
    $this->post(config('rsc.host_call_path'), [])->assertStatus(403);
});

it('names the address when one was configured and did not answer', function () {
    // Thrown rather than written into the body, so it renders the way every
    // other Laravel failure does: the debug page while developing, a 502 in
    // production. The message says what to run — the same shape as Laravel's
    // own "Run `npm run dev` or `npm run build`" for a missing Vite manifest.
    $this->withoutExceptionHandling();

    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:65535');

    try {
        $this->get('/a-page-with-no-route');
        $this->fail('expected the renderer to be reported as not running');
    } catch (RendererNotRunningException $e) {
        expect($e->getMessage())->toContain('npm run dev');
        expect($e->getMessage())->toContain('65535');
        expect($e->getStatusCode())->toBe(502);
    }
});

/**
 * The one server that cannot host this arrangement.
 *
 * `php artisan serve` runs a single worker, and forwarding a render needs two:
 * one is held for the page while the renderer works, and the renderer calls
 * back here for that page's data. Left alone it does not error — the host call
 * times out after 30s and the page answers 200 with the failure reported inside
 * its Suspense boundary — so the only symptom is slow pages missing their data.
 * These pin that it is refused up front instead, and that nothing else is.
 */
function wouldDeadlock(string $sapi, string $origin = 'http://localhost:8000'): ?string
{
    $method = new ReflectionMethod(RendererProxy::class, 'deadlockingBackend');

    return $method->invoke(new RendererProxy, Request::create($origin.'/a-page'), $sapi);
}

it('refuses to forward when the renderer will call back into this one worker', function () {
    putenv('PHP_CLI_SERVER_WORKERS');
    config()->set('app.url', 'http://localhost:8000');

    expect(wouldDeadlock('cli-server'))->toBe('http://localhost:8000');
});

it('allows it once the server actually has workers', function () {
    // Laravel passes this through only with --no-reload, which is why its
    // absence means one worker whatever the variable says in the shell.
    putenv('PHP_CLI_SERVER_WORKERS=6');
    config()->set('app.url', 'http://localhost:8000');

    expect(wouldDeadlock('cli-server'))->toBeNull();

    putenv('PHP_CLI_SERVER_WORKERS');
});

it('leaves a single-worker server alone when the host calls go somewhere else', function () {
    // Only proxying, never called back into — there is no second request to
    // starve, so this is a perfectly good thing to be running.
    putenv('PHP_CLI_SERVER_WORKERS');
    config()->set('app.url', 'http://elsewhere.test');

    expect(wouldDeadlock('cli-server'))->toBeNull();
});

it('says nothing under a server that is not php -S', function () {
    putenv('PHP_CLI_SERVER_WORKERS');
    config()->set('app.url', 'http://localhost:8000');

    expect(wouldDeadlock('fpm-fcgi'))->toBeNull();
    expect(wouldDeadlock('cli'))->toBeNull();
});

it('offers the two ways around, because there is no way through', function () {
    // `php -S` cannot answer a second request while it is blocked on the
    // first, so there is nothing to configure. Either serve the application on
    // something with workers, or stop proxying and address the renderer
    // directly — and the message has to name the backend, because that is the
    // part nobody can guess.
    $message = (new ProxyWouldDeadlockException('http://localhost:8000'))->getMessage();

    expect($message)->toContain('Herd');
    expect($message)->toContain('localhost:5173');
    expect($message)->toContain('http://localhost:8000');
});

it('does not forward back a request the renderer already handed over', function () {
    // Both sides forward what they cannot route to the other. Without the
    // marker a url neither owns bounces between them until something gives
    // out — and the two logs make it look like traffic rather than a loop.
    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:5173');

    $this->withHeader(RendererProxy::FALLBACK_HEADER, '1')
        ->get('/a-page-neither-side-owns')
        ->assertNotFound();
});

it('tells the renderer this already came from here', function () {
    // Otherwise the renderer hands it back and Laravel asks its own route
    // table the same question twice — a wasted round trip on every 404.
    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:1');

    $headers = (new ReflectionMethod(RendererProxy::class, 'forwardedHeaders'))
        ->invoke(new RendererProxy, Request::create('http://app.test/a-page'));

    expect($headers)->toContain(RendererProxy::PROXIED_HEADER.': 1');
});
