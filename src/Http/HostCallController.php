<?php

namespace RscKit\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function __invoke(Request $request): JsonResponse
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

        ['status' => $status, 'reply' => $reply] = $this->dispatcher->dispatch($body);

        return new JsonResponse($reply, $status);
    }
}
