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
