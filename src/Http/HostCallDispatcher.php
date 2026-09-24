<?php

namespace RscKit\Http;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Validation\ValidationException;
use JsonException;
use JsonSerializable;
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

    /** The most calls one batch may carry: the engine's own BATCH_LIMIT. */
    public const BATCH_LIMIT = 50;

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
        // A batch: several calls the renderer issued in one tick of a render,
        // answered in order, each with the status it would have had alone.
        // One Laravel request for a page's parallel reads rather than one
        // each - the framework boots once, the session is read once.
        if (isset($body['calls'])) {
            return $this->dispatchBatch($body['calls']);
        }

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
        // With debug on, that is. Off, the list is the application's whole
        // callable surface, handed to whoever guessed a name wrong.
        if (! in_array($name, $this->registry->names(), true)) {
            return $this->fail(404, config('app.debug') ? sprintf(
                'No host function named "%s". Registered: %s',
                $name,
                implode(', ', $this->registry->names()) ?: '(none)',
            ) : sprintf('No host function named "%s".', $name));
        }

        try {
            $result = $this->jsonReady($this->registry->execute($name, array_values($args)));
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
            //
            // Its status rides along: a middleware that redirected with a 302
            // meant a 302, and the engine's default is a 307, which replays a
            // POST at the destination. Its headers do not - the reply has no
            // field for them.
            return [
                'status' => 200,
                'reply' => ['redirect' => $e->getLocation(), 'redirectStatus' => $e->getStatus()],
            ];
        } catch (HttpExceptionInterface $e) {
            // A middleware that aborted with a status meant that status.
            // throttle answers 429, a policy 403, a signed-url check 403 — and
            // collapsing them all to 500 makes a rate-limited visitor
            // indistinguishable from a broken server. A 5xx is still a
            // failure, though, and reported like one.
            if ($e->getStatusCode() >= 500) {
                report($e);
            }

            return [
                'status' => $e->getStatusCode(),
                'reply' => ['error' => $e->getMessage() ?: 'Refused.', 'refusalStatus' => $e->getStatusCode()],
            ];
        } catch (\Throwable $e) {
            return $this->failure($e);
        } finally {
            // Taken on every way out, not only the successful one. A call that
            // marked a region and then threw left its mark behind, and the
            // next call in the batch carried it back as its own.
            $revalidate = $this->revalidation->flush();
        }

        $reply = ['result' => $result];

        // What the callable marked stale rides back with its result, so the
        // answer to an action can carry the re-rendered region rather than the
        // browser being told to ask again.
        if ($revalidate !== []) {
            $reply['revalidate'] = $revalidate;
        }

        return ['status' => 200, 'reply' => $reply];
    }

    /**
     * Run one call and encode its answer.
     *
     * Encoded here rather than by whoever sends it, so a result JSON cannot
     * carry - an INF, a string that is not UTF-8 - is this call's failure,
     * answered like any other, rather than an exception thrown by the
     * response after the call has already run.
     *
     * @param  array<string, mixed>  $body  the decoded request body, one call
     * @return array{status: int, json: string}
     */
    public function respond(array $body): array
    {
        ['status' => $status, 'reply' => $reply] = $this->dispatch($body);

        return $this->encode($status, $reply);
    }

    /**
     * @param  mixed  $calls  what the body carried under "calls"
     * @return array{status: int, reply: array<string, mixed>}
     */
    private function dispatchBatch(mixed $calls): array
    {
        if ($refusal = $this->malformedBatch($calls)) {
            return $refusal;
        }

        $replies = [];

        // In order, and every one of them: a refusal in the third call is
        // that call's answer, not a reason to leave the fourth unanswered.
        foreach ($calls as $call) {
            ['status' => $status, 'reply' => $reply] = $this->dispatch(is_array($call) ? $call : null);

            $replies[] = ['status' => $status] + $reply;
        }

        return ['status' => 200, 'reply' => ['replies' => $replies]];
    }

    /**
     * Whether a body is a batch the controller can stream, or what is wrong with it.
     *
     * @param  array<string, mixed>  $body
     * @return array{status: int, reply: array<string, mixed>}|null a refusal, or null for a batch that is one
     */
    public function batchRefusal(array $body): ?array
    {
        if (! isset($body['calls'])) {
            return null;
        }

        return $this->malformedBatch($body['calls']);
    }

    /**
     * What is wrong with a batch as a whole, if anything.
     *
     * @return array{status: int, reply: array<string, mixed>}|null
     */
    private function malformedBatch(mixed $calls): ?array
    {
        if (! is_array($calls) || $calls === [] || ! array_is_list($calls)) {
            return $this->fail(400, 'A batch needs a non-empty "calls" list.');
        }

        // The engine never puts more than this in one POST, so a batch that
        // does was not sent by the engine - and without a ceiling one request
        // could hold a worker for as many calls as it cared to list.
        if (count($calls) > self::BATCH_LIMIT) {
            return $this->fail(413, sprintf('A batch carries at most %d calls.', self::BATCH_LIMIT));
        }

        return null;
    }

    /**
     * A batch answered as it goes: one JSON line per call, each the moment
     * that call has finished, carrying its position in the batch.
     *
     * Calls run one after another here - PHP - but a page whose first read
     * is quick and third is slow paints the first before the third has
     * begun. Every call is answered as it would have been alone: a refusal
     * in the third is that call's answer, not a reason to leave the fourth
     * unanswered.
     *
     * @param  list<mixed>  $calls
     * @return \Generator<int, string> lines, without their newline
     */
    public function batch(array $calls): \Generator
    {
        foreach ($calls as $index => $call) {
            ['status' => $status, 'reply' => $reply] = $this->dispatch(is_array($call) ? $call : null);
            ['status' => $status, 'json' => $json] = $this->encode($status, $reply);

            // The reply is encoded on its own and the line built around it,
            // so a reply that cannot be encoded is this call's own 500 line.
            // Encoding the whole line threw instead - with the headers already
            // gone, which ended the stream and left every later call in the
            // batch unanswered.
            $head = json_encode(['index' => $index, 'status' => $status], JSON_THROW_ON_ERROR);

            yield $json === '{}' ? $head : substr($head, 0, -1).','.substr($json, 1);
        }
    }

    /**
     * A result in the shape JsonResponse would have sent it.
     *
     * A Collection, a model, a paginator: Laravel serialises each through the
     * interface it implements, and a function returning one expects what the
     * same value returned from a controller would have produced. Only the top
     * level needs this - json_encode honours JsonSerializable below it - and
     * doing it here gives the single answer and the batched one the same
     * encoding rather than one each.
     */
    private function jsonReady(mixed $result): mixed
    {
        return match (true) {
            $result instanceof Jsonable => json_decode($result->toJson(), false, 512, JSON_THROW_ON_ERROR),
            $result instanceof JsonSerializable => $result->jsonSerialize(),
            $result instanceof Arrayable => $result->toArray(),
            default => $result,
        };
    }

    /**
     * @param  array<string, mixed>  $reply
     * @return array{status: int, json: string}
     */
    private function encode(int $status, array $reply): array
    {
        try {
            return ['status' => $status, 'json' => json_encode($reply, JSON_THROW_ON_ERROR)];
        } catch (JsonException $e) {
            ['status' => $status, 'reply' => $reply] = $this->failure($e);

            return ['status' => $status, 'json' => json_encode($reply, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)];
        }
    }

    /**
     * A call that failed, rather than one that was refused.
     *
     * Reported, because nothing else will: the endpoint answers the failure
     * itself, so Laravel's handler never sees it, and a function that threw
     * on every page left no trace in the log. The message goes back only with
     * debug on. Off, it is whatever the exception said - a query and its
     * bindings, a path on the server - and the renderer puts what it is given
     * into an error a visitor can read.
     *
     * @return array{status: int, reply: array<string, mixed>}
     */
    private function failure(\Throwable $e): array
    {
        report($e);

        return $this->fail(500, config('app.debug') ? $e->getMessage() : 'Server Error');
    }

    /**
     * @return array{status: int, reply: array<string, mixed>}
     */
    private function fail(int $status, string $message): array
    {
        return ['status' => $status, 'reply' => ['error' => $message]];
    }
}
