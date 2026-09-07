<?php

namespace RscKit;

use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * This server has one worker, and proxying a render needs two.
 *
 * Laravel holds a worker for the whole page while it forwards to the renderer,
 * and the renderer calls back here for that page's data. On a server with a
 * single worker there is nobody left to answer, so both sides wait until the
 * host call times out.
 *
 * Thrown rather than allowed to happen, because the symptom is close to
 * unreadable: the page still answers 200 — a failed host call is reported
 * inside its Suspense boundary, not by failing the request — so all anyone sees
 * is that every page with data takes exactly thirty seconds and comes back
 * missing it. Nothing in either log names the cause. Thirty seconds of silence
 * is a worse answer than an immediate one.
 *
 * There is no fix to offer, only a way around: this is what `php -S` is, and
 * the message says to serve the app on something else or to skip the proxy by
 * opening the renderer directly.
 *
 * Only `php -S` reaches this. Herd, Valet, FPM and Octane all run several
 * workers, and the check requires the renderer's backend to be THIS server, so
 * a single-worker instance that only ever proxies is left alone.
 */
class ProxyWouldDeadlockException extends RuntimeException implements HttpExceptionInterface
{
    public function __construct(private string $backend)
    {
        parent::__construct(sprintf(
            'This page cannot be rendered: `php artisan serve` runs a single worker, and this '
                .'arrangement needs at least two. Laravel holds the worker while it proxies to '
                .'the renderer, and the renderer calls back to %s — this same server — for the '
                .'page data, with nobody left to answer. That is a limit of the server, not of '
                .'this package: it cannot serve a second request while it is waiting on the '
                ."first.\n\n"
                .'Serve the application through Herd, Valet, FPM or Octane, which all run '
                ."several workers.\n\n"
                .'Or open the renderer directly — http://localhost:5173 by default — and skip '
                .'the proxy altogether. Laravel then only answers host calls, one short request '
                .'each, which a single worker handles fine. The renderer owns only the RSC '
                .'route tree, so anything Laravel serves itself is not on that origin.',
            $backend,
        ));
    }

    public function getStatusCode(): int
    {
        return 500;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }

    public function backend(): string
    {
        return $this->backend;
    }
}
