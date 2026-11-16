<?php

// Decided before the package boots, so it is set as the file loads: a route
// table the build would have written, with a page at /, a param, a
// catch-all and a GET-only route.ts.
$manifest = sys_get_temp_dir().'/rsc-routes-'.getmypid().'.json';

file_put_contents($manifest, json_encode([
    // rscKit({ subdomains: ['merchant'] }), as the build writes it.
    'build' => ['subdomains' => ['merchant']],
    'routes' => [
        ['segments' => []],
        ['segments' => [['type' => 'static', 'value' => 'orders'], ['type' => 'param', 'value' => 'id']]],
        ['segments' => [['type' => 'static', 'value' => 'docs'], ['type' => 'catchAll', 'value' => 'rest']]],
        // A dynamic root route, /{team}/{app}: it has every url of two segments.
        ['segments' => [['type' => 'param', 'value' => 'team'], ['type' => 'param', 'value' => 'app']]],
        ['segments' => [['type' => 'static', 'value' => 'account'], ['type' => 'static', 'value' => 'settings']]],
        // Under a folder the build lists in subdomains.
        ['segments' => [['type' => 'static', 'value' => 'merchant']]],
        ['segments' => [['type' => 'static', 'value' => 'merchant'], ['type' => 'static', 'value' => 'onboarding']]],
        ['segments' => [['type' => 'static', 'value' => 'merchant'], ['type' => 'static', 'value' => 'orders'], ['type' => 'param', 'value' => 'id']]],
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

// With Laravel in front, a page route does not shadow the application's own: a
// dynamic /{team}/{app} has every url of two segments, /gitlab/connect among them,
// and the application's route for it is registered before the package's, so it
// answers. (With the renderer in front it is the other way round, and
// rscKit({ backendPaths }) is what names the urls Laravel owns.)
it("leaves the application's own route to it when a dynamic page route would also match", function () {
    $this->get('/gitlab/connect')->assertOk()->assertSee('laravel gitlab connect');
    // And the page route still takes the urls the app has no route for.
    $this->get('/acme/blog')->assertStatus(502);
});

// Named by its path, so Laravel's own route('login') - where `auth` sends a
// guest - finds a login page the tree has, and PHP can link to any page.
it('names a page route after its path', function () {
    expect(route('account.settings', absolute: false))->toBe('/account/settings')
        ->and(route('docs.rest', ['rest' => 'a/b'], absolute: false))->toBe('/docs/a/b');
});

it('keeps a name the application already gave a route', function () {
    // Tests\TestCase names its own /legacy-orders/{id} `orders.id`.
    expect(route('orders.id', ['id' => 7], absolute: false))->toBe('/legacy-orders/7');
});

// The renderer serves app/merchant/onboarding on both forms, and route() can
// build only one. With merchant in rscKit({ subdomains }) it is the subdomain's: built
// as /merchant/onboarding on merchant.example.test, the renderer read it as
// merchant/merchant/onboarding.
it('puts a page under a folder the build lists in subdomains on that subdomain', function () {
    expect(route('merchant.onboarding'))->toBe('http://merchant.example.test/onboarding')
        ->and(route('merchant'))->toBe('http://merchant.example.test')
        ->and(route('merchant.orders.id', ['id' => 7]))->toBe('http://merchant.example.test/orders/7')
        // Every other page is still a path on the current host.
        ->and(route('account.settings', absolute: false))->toBe('/account/settings');

    $this->get('http://merchant.example.test/onboarding')->assertStatus(502);
    $this->get('http://merchant.example.test/orders/7')->assertStatus(502);
});

// The path form is still the renderer's: no route of Laravel's has it, so the
// fallback hands it on, and the renderer answers it as it always did.
it('still hands the path form of a subdomain page to the renderer', function () {
    $this->get('http://example.test/merchant/onboarding')->assertStatus(502);
    $this->get('http://example.test/merchant')->assertStatus(502);
});
