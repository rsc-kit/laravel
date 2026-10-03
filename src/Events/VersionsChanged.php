<?php

namespace RscKit\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * "A version moved" - broadcast by Rsc::changed() when `rsc.broadcast` is on,
 * so a renderer listening on the channel asks Laravel which moved at once,
 * rather than on its next interval.
 *
 * It carries nothing. The channel is public - anyone with the app key can
 * subscribe - so the names, which may be per visitor (inbox:7), never go
 * over it; the renderer asks __rsc.changed, which answers as it always has.
 *
 * Sent now rather than queued, so a tab hears within a moment; and after the
 * surrounding transaction commits, so the renderer it wakes reads the change
 * rather than the data from before it.
 */
class VersionsChanged implements ShouldBroadcastNow, ShouldDispatchAfterCommit
{
    public function __construct(public readonly string $channel = 'rsc-versions') {}

    public function broadcastOn(): Channel
    {
        return new Channel($this->channel);
    }

    public function broadcastAs(): string
    {
        return 'rsc.changed';
    }

    /** @return array<string, never> */
    public function broadcastWith(): array
    {
        return [];
    }
}
