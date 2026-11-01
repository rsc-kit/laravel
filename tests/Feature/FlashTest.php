<?php

use Illuminate\Support\Facades\Route;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallController;
use RscKit\Http\HostCallDispatcher;
use RscKit\Rsc;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Rsc::flash() leaves the message @rsc-kit/core's useFlash() shows: the same
 * cookie the engine's flash() writes, read in the browser - so it must reach
 * the page's response unencrypted and readable by script, and a message the
 * visitor still carries keeps its place. The conformance suite checks the
 * same thing from the renderer's side.
 */
beforeEach(function () {
    config()->set('rsc.host_call_secret', 'flash-secret');
    Route::post('/__rsc/host-call', HostCallController::class)->middleware('web');
});

function flashCall(string $function, ?string $cookie = null): Cookie
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_'.str_replace('-', '_', strtoupper(HostCallDispatcher::SECRET_HEADER)) => 'flash-secret',
    ];

    // The test client reads cookies from this argument, not from a Cookie header.
    $cookies = $cookie === null ? [] : [Rsc::FLASH_COOKIE => $cookie];

    $response = test()->call('POST', '/__rsc/host-call', [], $cookies, [], $server, json_encode(['function' => $function, 'args' => []]));

    $response->assertOk();

    $flash = collect($response->headers->getCookies())->first(fn ($c) => $c->getName() === Rsc::FLASH_COOKIE);

    expect($flash)->not->toBeNull();

    return $flash;
}

it('leaves a message the browser can read, unencrypted', function () {
    app(CallableRegistry::class)->register('Flash.saved', fn () => Rsc::flash('Saved.'));

    $cookie = flashCall('Flash.saved');

    // EncryptCookies would have turned the value into a payload no script can parse.
    expect(json_decode($cookie->getValue(), true))->toBe([['message' => 'Saved.', 'kind' => 'success']])
        ->and($cookie->isHttpOnly())->toBeFalse()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->getSameSite())->toBe('lax')
        ->and($cookie->getMaxAge())->toBeLessThanOrEqual(60);
});

it('keeps a message the visitor still carries, and every one left in the call', function () {
    app(CallableRegistry::class)->register('Flash.twice', function () {
        Rsc::flash('Sent.');
        Rsc::flash('The copy bounced.', 'error');
    });

    $cookie = flashCall('Flash.twice', json_encode([['message' => 'Earlier.', 'kind' => 'info']]));

    expect(json_decode($cookie->getValue(), true))->toBe([
        ['message' => 'Earlier.', 'kind' => 'info'],
        ['message' => 'Sent.', 'kind' => 'success'],
        ['message' => 'The copy bounced.', 'kind' => 'error'],
    ]);
});

it('starts afresh over a carried cookie that is not a list of messages', function () {
    app(CallableRegistry::class)->register('Flash.saved', fn () => Rsc::flash('Saved.'));

    expect(json_decode(flashCall('Flash.saved', 'not json')->getValue(), true))->toBe([['message' => 'Saved.', 'kind' => 'success']]);
});

it('refuses a kind useFlash does not know', function () {
    Rsc::flash('Saved.', 'succes');
})->throws(InvalidArgumentException::class, 'not [succes]');
