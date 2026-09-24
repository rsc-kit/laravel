<?php

namespace RscKit\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The Laravel binding for a host call.
 *
 * Deliberately thin: everything that decides anything lives in
 * HostCallDispatcher, which knows nothing about Laravel. This is the shape a
 * second framework would copy — read the secret, decode the body, hand both
 * over — and it is why the same endpoint can exist for Symfony or Slim without
 * the logic being written twice.
 */
class HostCallController
{
    public function __construct(private HostCallDispatcher $dispatcher) {}

    public function __invoke(Request $request): JsonResponse|StreamedResponse
    {
        if (! $this->dispatcher->authorises($request->header(HostCallDispatcher::SECRET_HEADER))) {
            return new JsonResponse(['error' => 'Bad or missing host secret.'], 403);
        }

        // Decoded here rather than through $request->json(), which throws on
        // malformed input and would surface as a 500 — a caller sending
        // rubbish should be told it sent rubbish.
        $body = json_decode($request->getContent(), true);

        if (! is_array($body)) {
            return new JsonResponse(['error' => 'A host call body must be a JSON object.'], 400);
        }

        // A batch is answered as it goes: one line per call, flushed the
        // moment that call has finished, so the renderer resolves a fast
        // read while a slow sibling is still running and the page's
        // boundaries keep streaming independently. Headers leave before the
        // first call runs, which is the one cost: a cookie a batched read
        // queues has nothing to ride on. A read has no business setting one;
        // an action - a single call, never batched - still gets its cookies.
        if (isset($body['calls'])) {
            if ($refusal = $this->dispatcher->batchRefusal($body)) {
                return new JsonResponse($refusal['reply'], $refusal['status']);
            }

            $calls = $body['calls'];

            return new StreamedResponse(function () use ($calls, $request) {
                self::drainOutputBuffers();

                try {
                    foreach ($this->dispatcher->batch($calls) as $line) {
                        echo $line, "\n";

                        if (ob_get_level() > 0) {
                            ob_flush();
                        }

                        flush();
                    }
                } finally {
                    // StartSession saved the session when this response left
                    // the middleware - before this body ran, so before any of
                    // these calls did. What they wrote to it was never stored.
                    // Saved once, at the end, not after every call: each save
                    // ages the flash data, and a second one in one request
                    // discards what the first kept for the next.
                    if ($request->hasSession()) {
                        $request->session()->save();
                    }
                }
            }, 200, [
                'Content-Type' => 'application/x-ndjson',
                // A proxy that buffers would hold the fast answer behind the
                // slow one, which is the one thing this shape exists to avoid.
                'X-Accel-Buffering' => 'no',
            ]);
        }

        ['status' => $status, 'json' => $json] = $this->dispatcher->respond($body);

        return new JsonResponse($json, $status, [], 0, true);
    }

    /**
     * Close every output buffer between this response and the socket.
     *
     * Flushing only the top one hands its contents to the buffer below, and
     * php.ini's output_buffering is usually one: under FPM a line flushed
     * "the moment its call finished" waited there until 4KB had piled up, so
     * a fast read in a batch arrived with the slow one after all. The proxy
     * closes them all for the same reason.
     *
     * Not under the CLI SAPI, where there is no such buffer and whatever is
     * open belongs to something else - Octane's Swoole and RoadRunner workers
     * capture a response by buffering it, and a test runner captures output
     * the same way. Closing theirs sends the body nowhere.
     */
    public static function drainOutputBuffers(string $sapi = PHP_SAPI, int $floor = 0): void
    {
        if (in_array($sapi, ['cli', 'phpdbg'], true)) {
            return;
        }

        while (ob_get_level() > $floor) {
            ob_end_flush();
        }
    }
}
