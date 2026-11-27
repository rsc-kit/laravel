<?php

/**
 * The renderer's pages, ahead of the app's routes.
 *
 * A fresh application routes `/` to its welcome page, and the proxy is a
 * fallback - so after install the page at resources/js/app/page.tsx was
 * unreachable. The table the build writes is what says which urls are the
 * React tree's, and these turn it into the patterns Laravel registers first.
 */

use RscKit\Support\RendererRoutes;

test('a page is a GET and HEAD route from its segments', function () {
    $patterns = RendererRoutes::patterns([
        'routes' => [
            ['segments' => []],
            ['segments' => [['type' => 'static', 'value' => 'orders'], ['type' => 'param', 'value' => 'id']]],
        ],
    ]);

    expect($patterns)->toBe([
        ['/', [], ['GET', 'HEAD']],
        ['/orders/{id}', [], ['GET', 'HEAD']],
    ]);
});

test('a catch-all names the parameter to constrain', function () {
    $patterns = RendererRoutes::patterns([
        'routes' => [['segments' => [['type' => 'static', 'value' => 'docs'], ['type' => 'catchAll', 'value' => 'rest']]]],
    ]);

    expect($patterns)->toBe([['/docs/{rest}', ['rest'], ['GET', 'HEAD']]]);
});

test('a route.ts answers the methods it exports, HEAD beside a GET', function () {
    $patterns = RendererRoutes::patterns([
        'apis' => [['segments' => [['type' => 'static', 'value' => 'api'], ['type' => 'static', 'value' => 'health']], 'methods' => ['GET', 'POST']]],
    ]);

    expect($patterns)->toBe([['/api/health', [], ['GET', 'POST', 'HEAD']]]);
});

test('a table with nothing in it registers nothing', function () {
    expect(RendererRoutes::patterns([]))->toBe([])
        ->and(RendererRoutes::patterns(['routes' => [['segments' => 'nope']]]))->toBe([]);
});

test('a page is named by its path, a parameter by its name', function () {
    expect(RendererRoutes::names([
        'routes' => [
            ['segments' => []],
            ['segments' => [['type' => 'static', 'value' => 'login']]],
            ['segments' => [['type' => 'static', 'value' => 'merchant'], ['type' => 'static', 'value' => 'onboarding']]],
            ['segments' => [['type' => 'static', 'value' => 'orders'], ['type' => 'param', 'value' => 'id']]],
        ],
        'apis' => [['segments' => [['type' => 'static', 'value' => 'api']], 'methods' => ['GET']]],
    ]))->toBe([
        '/login' => 'login',
        '/merchant/onboarding' => 'merchant.onboarding',
        '/orders/{id}' => 'orders.id',
        '/' => 'home',
    ]);
});

test('the root page is home, unless a page at /home has the name', function () {
    expect(RendererRoutes::names(['routes' => [
        ['segments' => []],
        ['segments' => [['type' => 'static', 'value' => 'home']]],
    ]]))->toBe(['/home' => 'home']);
});

test('a page or route.ts that declares its name has it, by its pattern', function () {
    expect(RendererRoutes::declaredNames([
        'routes' => [
            ['segments' => [['type' => 'static', 'value' => 'verify-email'], ['type' => 'param', 'value' => 'id'], ['type' => 'param', 'value' => 'hash']], 'routeName' => 'verification.verify'],
            ['segments' => [['type' => 'static', 'value' => 'about']]],
        ],
        'apis' => [['segments' => [['type' => 'static', 'value' => 'webhooks']], 'methods' => ['POST'], 'routeName' => 'webhooks']],
    ]))->toBe([
        '/verify-email/{id}/{hash}' => 'verification.verify',
        '/webhooks' => 'webhooks',
    ]);
});
