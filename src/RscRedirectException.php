<?php

namespace RscKit;

/**
 * How a redirect reaches the host-call reply - a guard's, or one a callable
 * returned. @internal: an app returns redirect(), to_route() or back(), or
 * throws Laravel's HttpResponseException from deeper in.
 *
 * @internal
 */
class RscRedirectException extends \RuntimeException
{
    /**
     * @param  list<array{0: string, 1: string}>  $headers  Set by middleware
     *                                                      before it redirected — remembering where someone was
     *                                                      going is the usual one, and it has to survive the trip.
     */
    public function __construct(
        private string $location,
        private int $status = 302,
        private array $headers = [],
    ) {
        parent::__construct("Redirect to {$location}");
    }

    public function getLocation(): string
    {
        return $this->location;
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
