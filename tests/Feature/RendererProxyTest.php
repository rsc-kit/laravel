<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
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

/**
 * A server action is a POST, and the proxy used to refuse one.
 *
 * Route::fallback() registers GET and HEAD only. A server action posts to
 * /_rsc/action — a path Laravel does not route — so the fallback matched the
 * uri, refused the method, and answered 405 before the renderer ever saw it.
 * The browser then decoded a failed row and unmounted the document, so the
 * symptom was a blank page from a working application, on every action ever
 * submitted through this proxy.
 */
it('hands the renderer a server action, which is a POST', function () {
    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:1');

    // Not 405. Reaching the renderer is the point; that it is not running at
    // 127.0.0.1:1 is this test's business, not the router's.
    expect($this->post('/_rsc/action')->status())->not->toBe(405);
});

it('hands it every method a page might use, not only the ones pages use', function () {
    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:1');

    foreach (['put', 'patch', 'delete'] as $method) {
        expect($this->{$method}('/_rsc/action')->status())->not->toBe(405);
    }
});

it('still lets a real route win, whatever the method', function () {
    // ->fallback() is what keeps this last. An ordinary any-method catch-all
    // would shadow every route declared after it.
    Route::post('/mine', fn () => 'mine');
    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:1');

    expect($this->post('/mine')->getContent())->toBe('mine');
});

/**
 * A renderer that answers, for what only shows against a real answer.
 *
 * Started once per process on a free port and stopped when the process ends.
 */
function fakeRenderer(): string
{
    static $url = null;

    if ($url !== null) {
        return $url;
    }

    $probe = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($probe, false), ':'), 1);
    fclose($probe);

    $process = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", dirname(__DIR__).'/fixtures/renderer.php'],
        [['pipe', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']],
        $pipes,
    );

    register_shutdown_function(fn () => proc_terminate($process));

    for ($i = 0; $i < 100; $i++) {
        if ($socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1)) {
            fclose($socket);

            break;
        }

        usleep(20_000);
    }

    return $url = "http://127.0.0.1:{$port}";
}

it('does not keep a HEAD waiting for a body that is not coming', function () {
    // The renderer's answer to a HEAD says how long its body would have been.
    // Told only the verb, curl waited for that body until the timeout.
    $renderer = fakeRenderer();

    $options = (new ReflectionMethod(RendererProxy::class, 'requestOptions'))
        ->invoke(new RendererProxy, Request::create('http://app.test/a-page', 'HEAD'));

    $handle = curl_init($renderer.'/a-page');
    curl_setopt_array($handle, $options + [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);

    $started = microtime(true);
    curl_exec($handle);

    expect(curl_errno($handle))->toBe(0);
    expect(curl_getinfo($handle, CURLINFO_RESPONSE_CODE))->toBe(200);
    expect(microtime(true) - $started)->toBeLessThan(1.5);
});

it('hands the renderer the visitor Laravel resolved, not the one they claimed', function () {
    // Anyone can send X-Forwarded-For. Forwarded as it came, the visitor's
    // own claim sat ahead of the proxy's and the renderer believed it.
    $request = Request::create('http://app.test/a-page', 'GET', server: [
        'REMOTE_ADDR' => '10.0.0.7',
        'HTTP_X_FORWARDED_FOR' => '6.6.6.6',
        'HTTP_X_FORWARDED_HOST' => 'evil.test',
        'HTTP_X_FORWARDED_PROTO' => 'https',
        'HTTP_X_FORWARDED_PORT' => '8443',
        'HTTP_X_FORWARDED_PREFIX' => '/evil',
        'HTTP_FORWARDED' => 'for=6.6.6.6;host=evil.test',
        'HTTP_X_REAL_IP' => '6.6.6.6',
    ]);

    $headers = (new ReflectionMethod(RendererProxy::class, 'forwardedHeaders'))
        ->invoke(new RendererProxy, $request);

    $named = fn (string $name) => array_values(array_map(
        fn (string $line) => trim(explode(':', $line, 2)[1]),
        array_filter($headers, fn (string $line) => strcasecmp(explode(':', $line, 2)[0], $name) === 0),
    ));

    expect($named('X-Forwarded-For'))->toBe(['10.0.0.7']);
    expect($named('X-Real-IP'))->toBe(['10.0.0.7']);
    expect($named('X-Forwarded-Host'))->toBe(['app.test']);
    expect($named('X-Forwarded-Proto'))->toBe(['http']);
    expect($named('X-Forwarded-Port'))->toBe(['80']);
    expect($named('X-Forwarded-Prefix'))->toBe([]);
    expect($named('Forwarded'))->toBe([]);
    expect(implode("\n", $headers))->not->toContain('6.6.6.6');
});

/**
 * Every Set-Cookie the browser would be sent, in order.
 *
 * Read the way the response writes them: the renderer's own lines after
 * whatever the middleware stack left.
 *
 * @return list<string>
 */
function sentCookies(TestResponse $response): array
{
    $base = $response->baseResponse;

    return method_exists($base, 'setCookieLines')
        ? $base->setCookieLines()
        : array_map('strval', $base->headers->getCookies());
}

it('lets the renderer\'s cookies reach the browser as the renderer sent them', function () {
    // They came from a host call, which ran under EncryptCookies already.
    // Encrypted a second time, nothing could read them; overwritten by the
    // proxy's own session cookie, a login made during the render was
    // replaced by the session it had just migrated away from.
    config()->set('session.driver', 'file');
    config()->set('session.files', sys_get_temp_dir());
    file_put_contents(config('rsc.hot_file'), fakeRenderer());

    $response = $this->get('/sets-cookies');

    $response->assertOk();

    $cookies = sentCookies($response);
    $sessions = array_values(array_filter($cookies, fn (string $c) => str_starts_with($c, config('session.cookie').'=')));

    expect($sessions)->toBe([
        'laravel_session=from-the-host-call%3D%3D; expires=Thu, 01 Jan 2099 00:00:00 GMT; Max-Age=999; path=/; httponly; samesite=lax',
    ]);
    expect($cookies)->toContain('remember_me=abc123; Path=/; HttpOnly');
});

it('still sends the proxy\'s own cookies when the renderer set none of that name', function () {
    // The session the proxy started - and the XSRF-TOKEN a page's actions
    // post back with - are still the visitor's when the renderer said nothing
    // about them.
    config()->set('session.driver', 'file');
    config()->set('session.files', sys_get_temp_dir());
    file_put_contents(config('rsc.hot_file'), fakeRenderer());

    $cookies = implode("\n", sentCookies($this->get('/sets-nothing')->assertOk()));

    expect($cookies)->toContain(config('session.cookie').'=');
    expect($cookies)->toContain('XSRF-TOKEN=');
});
