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

            return new StreamedResponse(function () use ($calls) {
                foreach ($this->dispatcher->batch($calls) as $line) {
                    echo $line, "\n";

                    if (ob_get_level() > 0) {
                        ob_flush();
                    }

                    flush();
                }
            }, 200, [
                'Content-Type' => 'application/x-ndjson',
                // A proxy that buffers would hold the fast answer behind the
                // slow one, which is the one thing this shape exists to avoid.
                'X-Accel-Buffering' => 'no',
            ]);
        }

        ['status' => $status, 'reply' => $reply] = $this->dispatcher->dispatch($body);

        return new JsonResponse($reply, $status);
    }
}
