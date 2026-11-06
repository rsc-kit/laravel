<?php

namespace RscKit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Telling the CDN in front that kept pages changed.
 *
 * A `revalidate` page goes out with the `Cache-Tag` of every `cache()` answer
 * it used, and the names Rsc::changed() moves are those tags. The change is
 * made here and never passes through the renderer, so on Homeport - where
 * HOMEPORT_PURGE_URL is set - this posts the purge itself:
 *
 *     POST $HOMEPORT_PURGE_URL  {"tags": ["posts", "post:1"]}
 *
 * After the transaction commits, since a CDN that fetched the page again in
 * between would keep the old data; and after the answer has gone, through
 * defer(), so a slow edge never holds up the request that made the change.
 * Every name changed during one request goes out as one purge. A purge that
 * fails is logged and never fails the change: the edge still drops its copy
 * at s-maxage.
 */
class Purge
{
    /** Homeport takes at most this many tags per call. */
    public const LIMIT = 100;

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
        $tags = array_keys($this->pending);
        $this->pending = [];

        foreach (array_chunk($tags, self::LIMIT) as $chunk) {
            $this->send(['tags' => $chunk]);
        }
    }

    /** @param  array{tags?: list<string>, paths?: list<string>, all?: bool}  $body */
    private function send(array $body, bool $retried = false): void
    {
        try {
            $response = Http::asJson()->timeout(5)->post((string) $this->url, $body);
        } catch (Throwable $e) {
            Log::warning('rsc-kit: purging the CDN failed; its copy is dropped at s-maxage instead: '.$e->getMessage());

            return;
        }

        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429 && ! $retried) {
            usleep(min(max((int) $response->header('Retry-After'), 1), 5) * 1_000_000);

            $this->send($body, true);

            return;
        }

        Log::warning("rsc-kit: the CDN answered {$response->status()} to a purge; its copy is dropped at s-maxage instead.", $body);
    }
}
