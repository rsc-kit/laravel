<?php

namespace RscKit;

use Illuminate\Queue\NullQueue;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Telling the CDN in front that kept pages changed.
 *
 * A `revalidate` page goes out with the `Cache-Tag` of every `cache()` answer
 * it used, and the names Rsc::changed() moves are those tags. The change is
 * made here and never passes through the renderer, so with RSC_PURGE_URL set
 * (or the HOMEPORT_PURGE_URL Homeport sets) this posts the purge itself:
 *
 *     POST $RSC_PURGE_URL  {"tags": ["posts", "post:1"]}
 *
 * After the transaction commits, since a CDN that fetched the page again in
 * between would keep the old data; and after the answer has gone, through
 * defer(), so a slow edge never holds up the request that made the change.
 * Every name changed during one request goes out as one purge. A purge that
 * fails is logged and never fails the change: the edge still drops its copy
 * at its max-age in CDN-Cache-Control.
 *
 * A 429 is tried once more after its Retry-After, at most five seconds, as a
 * queued job, so no PHP worker sleeps through the wait. Without a queue that
 * can delay a job the wait happens here instead, after the answer has gone.
 */
class Purge
{
    /** Homeport takes at most this many tags per call. */
    public const LIMIT = 100;

    /** The longest tag Homeport takes, in bytes. */
    public const TAG_BYTES = 1024;

    /** @var array<string, true> */
    private array $pending = [];

    public function __construct(private readonly ?string $url = null) {}

    /**
     * Purge these tags once the transaction commits and the answer has gone.
     *
     * @param  list<string>  $tags
     */
    public function later(array $tags): void
    {
        if ($this->url === null || $this->url === '' || $tags === []) {
            return;
        }

        // Immediately outside a transaction; dropped if it rolls back.
        DB::afterCommit(function () use ($tags): void {
            foreach ($tags as $tag) {
                $this->pending[$tag] = true;
            }

            // Named, so a second change in the same request replaces the
            // callback rather than adding one: one purge per request. Always,
            // because a call that answers 422 after its change still made it.
            defer(fn () => $this->flush(), 'rsc-kit.purge', always: true);
        });
    }

    /** Send what is pending now. */
    public function flush(): void
    {
        // array_keys, then cast: PHP makes a numeric tag an integer key. One too
        // long would cost the whole call, and the edge keeps no page carrying it.
        $tags = array_filter(array_map('strval', array_keys($this->pending)), fn (string $tag) => strlen($tag) <= self::TAG_BYTES);
        $this->pending = [];

        foreach (array_chunk($tags, self::LIMIT) as $chunk) {
            $this->send(['tags' => $chunk]);
        }
    }

    /**
     * The one retry of a call the CDN answered 429: a second 429 is logged.
     *
     * @param  array{tags?: list<string>, paths?: list<string>, all?: bool}  $body
     */
    public function retry(array $body): void
    {
        $this->send($body, true);
    }

    /** @param  array{tags?: list<string>, paths?: list<string>, all?: bool}  $body */
    private function send(array $body, bool $retried = false): void
    {
        try {
            $response = Http::asJson()->timeout(5)->post((string) $this->url, $body);
        } catch (Throwable $e) {
            Log::warning('rsc-kit: purging the CDN failed; its copy is dropped at CDN-Cache-Control\'s max-age instead: '.$e->getMessage());

            return;
        }

        // Only a 202 means it was accepted.
        if ($response->status() === 202) {
            return;
        }

        if ($response->status() === 429 && ! $retried) {
            $seconds = min(max((int) $response->header('Retry-After'), 1), 5);

            if (! $this->queueRetry($body, $seconds)) {
                usleep($seconds * 1_000_000);

                $this->retry($body);
            }

            return;
        }

        Log::warning("rsc-kit: the CDN answered {$response->status()} to a purge; its copy is dropped at CDN-Cache-Control's max-age instead.", $body);
    }

    /**
     * Sync, deferred and background queues ignore a delay, and the null queue
     * drops the job, so those are a false here, as is a queue that is down.
     *
     * @param  array{tags?: list<string>, paths?: list<string>, all?: bool}  $body
     */
    private function queueRetry(array $body, int $seconds): bool
    {
        try {
            $queue = Queue::connection();

            if ($queue instanceof SyncQueue || $queue instanceof NullQueue) {
                return false;
            }

            $queue->later($seconds, new RetryPurge($body));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
