<?php

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;

/**
 * Route middleware judging the page, not the call.
 *
 * The call is a POST from the renderer to /__rsc/host-call, so middleware run
 * against it saw the renderer: `signed` checked the endpoint's url and refused
 * every link, every visitor shared the renderer's address and with it one
 * `throttle` bucket, and `can:update,invoice` had no {invoice}. The engine now
 * sends the page beside the names and the visitor's address on every call;
 * these go through the real endpoint, the way the renderer reaches it.
 */
beforeEach(function () {
    config()->set('rsc.host_call_secret', 'page-secret');
    Route::post('/__rsc/host-call', HostCallController::class)->middleware('web');
});

/** @param  array<string, string>  $headers  extra request headers */
function askAsVisitor(array $body, array $headers = []): TestResponse
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_RSC_HOST_SECRET' => 'page-secret',
    ];

    foreach ($headers as $name => $value) {
        $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }

    return test()->call('POST', '/__rsc/host-call', [], [], [], $server, json_encode($body));
}

/** @param  array{url: string, params?: array<string, string>}|null  $page */
function guardPage(array $names, ?array $page, array $headers = []): TestResponse
{
    $args = $page === null ? [$names] : [$names, $page + ['params' => []]];

    return askAsVisitor(['function' => '__rsc.middleware', 'args' => $args], $headers);
}

describe('the visitor\'s address', function () {
    it('is what $request->ip() answers, not the renderer\'s', function () {
        app()->forgetInstance(HostCallDispatcher::class);
        app(CallableRegistry::class)->register('Who.ip', fn () => request()->ip());

        askAsVisitor(['function' => 'Who.ip', 'args' => []], [HostCallDispatcher::CLIENT_IP_HEADER => '203.0.113.7'])
            ->assertOk()
            ->assertJson(['result' => '203.0.113.7']);
    });

    it('wins over a forwarded chain, which trusting the renderer would believe', function () {
        app()->forgetInstance(HostCallDispatcher::class);
        app(CallableRegistry::class)->register('Who.ip', fn () => request()->ip());
        Request::setTrustedProxies(['0.0.0.0/0'], Request::HEADER_X_FORWARDED_FOR);

        try {
            askAsVisitor(['function' => 'Who.ip', 'args' => []], [
                HostCallDispatcher::CLIENT_IP_HEADER => '203.0.113.7',
                'X-Forwarded-For' => '6.6.6.6',
            ])->assertJson(['result' => '203.0.113.7']);
        } finally {
            Request::setTrustedProxies([], 0);
        }
    });

    it('is ignored when it is not an address', function () {
        app()->forgetInstance(HostCallDispatcher::class);
        app(CallableRegistry::class)->register('Who.ip', fn () => request()->ip());

        askAsVisitor(['function' => 'Who.ip', 'args' => []], [HostCallDispatcher::CLIENT_IP_HEADER => 'not-an-ip'])
            ->assertJson(['result' => '127.0.0.1']);
    });

    it('keeps a throttle per visitor', function () {
        $page = ['url' => 'http://localhost/login'];

        guardPage(['throttle:2,1'], $page, [HostCallDispatcher::CLIENT_IP_HEADER => '203.0.113.7'])->assertJson(['result' => true]);
        guardPage(['throttle:2,1'], $page, [HostCallDispatcher::CLIENT_IP_HEADER => '203.0.113.7'])->assertJson(['result' => true]);
        guardPage(['throttle:2,1'], $page, [HostCallDispatcher::CLIENT_IP_HEADER => '203.0.113.7'])->assertStatus(429);

        // Somebody else is not slowed down by the first visitor.
        guardPage(['throttle:2,1'], $page, [HostCallDispatcher::CLIENT_IP_HEADER => '198.51.100.4'])->assertJson(['result' => true]);
    });
});

describe('the page', function () {
    beforeEach(function () {
        Route::get('/owner-verification/{owner}', fn () => '')->name('owner.verify');
    });

    it('lets signed check the link the visitor followed', function () {
        $link = URL::signedRoute('owner.verify', ['owner' => 5]);

        guardPage(['signed'], ['url' => $link, 'params' => ['owner' => '5']])->assertJson(['result' => true]);
    });

    it('refuses a link whose signature does not match', function () {
        $link = URL::signedRoute('owner.verify', ['owner' => 5]);

        guardPage(['signed'], ['url' => str_replace('/5?', '/6?', $link), 'params' => ['owner' => '6']])->assertStatus(403);
    });

    it('still refuses signed when the engine sent no page', function () {
        guardPage(['signed'], null)->assertStatus(403);
    });

    it('hands can: the bound model', function () {
        Route::bind('invoice', fn (string $id) => (object) ['id' => $id]);
        Gate::define('view-invoice', fn (?User $user, object $invoice) => $invoice->id === '7');

        guardPage(['can:view-invoice,invoice'], ['url' => 'http://localhost/invoices/7', 'params' => ['invoice' => '7']])
            ->assertJson(['result' => true]);
        guardPage(['can:view-invoice,invoice'], ['url' => 'http://localhost/invoices/8', 'params' => ['invoice' => '8']])
            ->assertStatus(403);
    });

    it('is the request a middleware is handed, and request() too', function () {
        $seen = [];

        app('router')->aliasMiddleware('looks', function ($request, Closure $next) use (&$seen) {
            $seen = [$request->fullUrl(), request()->path(), $request->route('order'), $request->header('X-Rsc-Host-Secret')];

            return $next($request);
        });

        guardPage(['looks'], ['url' => 'https://shop.example.com/orders/7?tab=items', 'params' => ['order' => '7']])
            ->assertJson(['result' => true]);

        // Never the call's secret: the page's request carries nothing of the call.
        expect($seen)->toBe(['https://shop.example.com/orders/7?tab=items', 'orders/7', '7', null]);
    });
});
