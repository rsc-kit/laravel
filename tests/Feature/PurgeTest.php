<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RscKit\Purge;
use RscKit\Rsc;

/**
 * Rsc::changed() tells the CDN keeping pages - Homeport, where
 * HOMEPORT_PURGE_URL is set - once the change is made and the answer has gone.
 */
beforeEach(function () {
    Cache::flush();
    config()->set('rsc.purge_url', 'http://purge.test/');
    app()->forgetInstance(Purge::class);
    Http::fake(['purge.test/*' => Http::response(null, 202)]);
});

/** What the request's deferred work would run once the answer has gone. */
function answerSent(): void
{
    app(DeferredCallbackCollection::class)->invoke();
}

it('posts every name a request changed as one purge, after the answer', function () {
    Rsc::changed('posts', 'post:1');
    Rsc::changed('posts');

    Http::assertNothingSent();

    answerSent();

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'http://purge.test/'
        && $request->isJson()
        && $request->data() === ['tags' => ['posts', 'post:1']]);
});

it('purges nothing for a change a transaction rolled back', function () {
    DB::beginTransaction();
    Rsc::changed('posts');
    DB::rollBack();

    answerSent();

    Http::assertNothingSent();
});

it('waits for the transaction to commit', function () {
    DB::transaction(function () {
        Rsc::changed('posts');

        expect(app(DeferredCallbackCollection::class)->count())->toBe(0);
    });

    answerSent();

    Http::assertSent(fn (Request $request) => $request->data() === ['tags' => ['posts']]);
});

it('purges nothing without HOMEPORT_PURGE_URL', function () {
    config()->set('rsc.purge_url', null);
    app()->forgetInstance(Purge::class);

    Rsc::changed('posts');
    answerSent();

    Http::assertNothingSent();
});

it('sends at most 100 tags a call', function () {
    Rsc::changed(...array_map(fn (int $i) => "tag:$i", range(1, 250)));
    answerSent();

    Http::assertSentCount(3);
});

it('leaves out a tag too long to purge, so it cannot cost the rest of the call', function () {
    Rsc::changed('posts', str_repeat('x', 1025), str_repeat('y', 1024));
    answerSent();

    Http::assertSent(fn (Request $request) => $request['tags'] === ['posts', str_repeat('y', 1024)]);
});

it('takes only a 202 as accepted', function () {
    config()->set('rsc.purge_url', 'http://ok.test/');
    app()->forgetInstance(Purge::class);
    Http::fake(['ok.test/*' => Http::response(null, 200)]);
    Log::shouldReceive('warning')->once();

    Rsc::changed('posts');
    answerSent();
});

it('never fails the change: a refused purge is logged', function () {
    // The first matching fake wins, so a host of its own.
    config()->set('rsc.purge_url', 'http://refusing.test/');
    app()->forgetInstance(Purge::class);
    Http::fake(['refusing.test/*' => Http::response(null, 400)]);
    Log::shouldReceive('warning')->once();

    Rsc::changed('posts');
    answerSent();
});
