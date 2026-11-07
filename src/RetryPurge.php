<?php

namespace RscKit;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The one retry of a purge the CDN answered 429: Purge::send() queues this
 * with the Retry-After as its delay, so no worker sleeps through the wait.
 */
class RetryPurge implements ShouldQueue
{
    /** The retry is the only one: a second 429 is logged, never queued again. */
    public int $tries = 1;

    /** @param  array{tags?: list<string>, paths?: list<string>, all?: bool}  $body */
    public function __construct(public readonly array $body) {}

    public function handle(Purge $purge): void
    {
        $purge->retry($this->body);
    }
}
