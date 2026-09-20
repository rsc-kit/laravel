<?php

// Decided before the package boots, so it is set as the file loads: a route
// table the build would have written, with a page at /, a param, a
// catch-all and a GET-only route.ts.
$manifest = sys_get_temp_dir().'/rsc-routes-'.getmypid().'.json';

file_put_contents($manifest, json_encode([
    'routes' => [
        ['segments' => []],
        ['segments' => [['type' => 'static', 'value' => 'orders'], ['type' => 'param', 'value' => 'id']]],
        ['segments' => [['type' => 'static', 'value' => 'docs'], ['type' => 'catchAll', 'value' => 'rest']]],
    ],
    'apis' => [
        ['segments' => [['type' => 'static', 'value' => 'api'], ['type' => 'static', 'value' => 'health']], 'methods' => ['GET']],
    ],
]));
putenv('RSC_TEST_ROUTES_MANIFEST='.$manifest);

afterAll(function () use ($manifest) {
    putenv('RSC_TEST_ROUTES_MANIFEST');
    @unlink($manifest);
});

/**
 * The React tree's pages win the urls they have, ahead of routes/web.php.
 *
 * A fresh application routes `/` to its welcome page, and the proxy was a
 * fallback: after install, the page at resources/js/app/page.tsx never
 * answered and the welcome page stood where the docs promised React. The
 * rule is per url - if the React tree has it, React renders it - and the
 * table the build writes is what says which urls those are.
 */
beforeEach(function () {
    config()->set('app.debug', false);
    config()->set('rsc.hot_file', sys_get_temp_dir().'/rsc-hot-pages-'.getmypid());
    config()->set('rsc.renderer_url', null);
    // Something to forward to - refused, which is how a test can see the
    // proxy took the request rather than the app's route.
    file_put_contents(config('rsc.hot_file'), 'http://127.0.0.1:65535');
});

afterEach(fn () => @unlink(config('rsc.hot_file')));

// The application's routes - the welcome page at /, and the rest - are
// registered by the test case the way routes/web.php is: in a booted
// callback that runs before the package's. See Tests\TestCase.

it('routes / to the renderer even when the app has a route for it', function () {
    // 502 is the proxy failing to reach a renderer that is not there; 200
    // 'welcome' would be the app's route having won.
    $this->get('/')->assertStatus(502);
});

it('routes a page with a parameter and a catch-all', function () {
    $this->get('/orders/42')->assertStatus(502);
    $this->get('/docs/a/b/c')->assertStatus(502);
});

it("routes a route.ts's methods, and leaves the rest to the app", function () {
    $this->get('/api/health')->assertStatus(502);
    // The route.ts exports GET only; a POST is still Laravel's.
    $this->post('/api/health')->assertOk()->assertSee('posted here');
});

it('leaves a url the tree does not have to the app', function () {
    $this->get('/login')->assertOk()->assertSee('laravel login');
});
