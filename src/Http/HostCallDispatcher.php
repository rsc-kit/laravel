<?php

namespace RscKit\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use RscKit\CallableRegistry;
use RscKit\Revalidation;
use RscKit\RscRedirectException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Answers a host call over HTTP.
 *
 * The same conversation the callback socket has, in a form any language can
 * hold up its end of: a JSON body in, a JSON body and a status out. Nothing
 * here touches an HTTP framework — the caller hands it a decoded body and gets
 * back a status and an array — so binding it to Laravel, to a PSR-15 pipeline,
 * or to anything else is a wrapper rather than a port.
 *
 * The reply shape is PROTOCOL.md's, and it is deliberately the same one the
 * frame protocol already carries. What changes is the transport, not what a
 * refusal or a redirect means.
 */
class HostCallDispatcher
{
    /** The header the shared secret arrives on. */
    public const SECRET_HEADER = 'X-Rsc-Host-Secret';

    public function __construct(
        private CallableRegistry $registry,
        private Revalidation $revalidation,
        private string $secret,
    ) {}

    /**
     * Whether the caller presented the right secret.
     *
     * This endpoint runs functions by name with none of the application's
     * routing in front of it, so the check is not optional and the comparison
     * is not `===`: the secret's length is already known to anyone who can
     * read the config, and a timing difference is the rest of it.
     */
    public function authorises(?string $presented): bool
    {
        // An unconfigured secret authorises nobody, checked before the
        // comparison rather than trusted to it: hash_equals('', '') is TRUE,
        // so a host that never opted in would answer to anyone sending an
        // empty header. The service provider does not register the route
        // without a secret, and this is the reason not to rely on that being
        // the only way one of these is ever built.
        if ($this->secret === '') {
            return false;
        }

        return $presented !== null && hash_equals($this->secret, $presented);
    }

    /**
     * Run one call.
     *
     * @param  array<string, mixed>|null  $body  the decoded request body
     * @return array{status: int, reply: array<string, mixed>}
     */
    public function dispatch(?array $body): array
    {
        $name = $body['function'] ?? null;

        if (! is_string($name) || $name === '') {
            return $this->fail(400, 'A host call needs a "function" name.');
        }

        $args = $body['args'] ?? [];

        if (! is_array($args)) {
            return $this->fail(400, 'A host call\'s "args" must be an array.');
        }

        // Named, and the alternatives listed, because the caller deliberately
        // cannot say which function is missing — it does not know what this
        // host registered, and only this side can tell a typo from a rename.
        if (! in_array($name, $this->registry->names(), true)) {
            return $this->fail(404, sprintf(
                'No host function named "%s". Registered: %s',
                $name,
                implode(', ', $this->registry->names()) ?: '(none)',
            ));
        }

        try {
            $result = $this->registry->execute($name, array_values($args));
        } catch (ValidationException $e) {
            // A refusal is an answer, not a failure. It travels in its own
            // field so the renderer never has to read a message to tell an
            // invalid form from a broken server.
            return [
                'status' => 422,
                'reply' => ['validationErrors' => $e->errors(), 'error' => $e->getMessage()],
            ];
        } catch (AuthenticationException $e) {
            return [
                'status' => 401,
                'reply' => ['unauthenticated' => true, 'error' => $e->getMessage()],
            ];
        } catch (AuthorizationException $e) {
            return [
                'status' => 403,
                'reply' => ['unauthorized' => true, 'error' => $e->getMessage()],
            ];
        } catch (RscRedirectException $e) {
            // Answered 200 with the destination in the body, never as a 3xx.
            // An HTTP client follows a redirect transparently, so a real one
            // here would send this call to the destination and hand whatever
            // came back to the renderer as if it were the function's result.
            return [
                'status' => 200,
                'reply' => ['redirect' => $e->getLocation()],
            ];
        } catch (HttpExceptionInterface $e) {
            // A middleware that aborted with a status meant that status.
            // throttle answers 429, a policy 403, a signed-url check 403 — and
            // collapsing them all to 500 makes a rate-limited visitor
            // indistinguishable from a broken server.
            return [
                'status' => $e->getStatusCode(),
                'reply' => ['error' => $e->getMessage() ?: 'Refused.', 'refusalStatus' => $e->getStatusCode()],
            ];
        } catch (\Throwable $e) {
            return $this->fail(500, $e->getMessage());
        }

        $reply = ['result' => $result];

        // What the callable marked stale rides back with its result, so the
        // answer to an action can carry the re-rendered region rather than the
        // browser being told to ask again.
        $revalidate = $this->revalidation->flush();

        if ($revalidate !== []) {
            $reply['revalidate'] = $revalidate;
        }

        return ['status' => 200, 'reply' => $reply];
    }

    /**
     * @return array{status: int, reply: array<string, mixed>}
     */
    private function fail(int $status, string $message): array
    {
        return ['status' => $status, 'reply' => ['error' => $message]];
    }
}
