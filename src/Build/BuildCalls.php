<?php

namespace RscKit\Build;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Cookie\CookieJar;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use RscKit\CallableRegistry;
use RscKit\Http\HostCallDispatcher;

/**
 * Answers rpc() for a build.
 *
 * Nothing is serving while the app is built, and there is no visitor. A
 * function that does not need one is run and its answer stored in the page; a
 * function that reaches for one is answered "needs a visitor", which leaves
 * that part of the page to the request.
 */
class BuildCalls
{
    /** A reply that carries nothing but whose it would have been. */
    private const NEEDS_SOMEONE = [401 => 'who is signed in', 403 => 'what they may do'];

    private Visitor $visitor;

    public function __construct(private Application $app, private CallableRegistry $registry)
    {
        $this->visitor = new Visitor;
    }

    /**
     * Answer one line.
     *
     * @return array{json: string, last: bool} the answer's line, and whether this process answers no more
     */
    public function answer(string $line): array
    {
        $call = json_decode($line, true);
        $id = is_array($call) ? ($call['id'] ?? null) : null;

        if (! is_int($id) && ! is_string($id)) {
            return ['json' => $this->encode(['id' => null, 'status' => 400, 'error' => 'A build call needs an "id".']), 'last' => false];
        }

        $name = is_string($call['function'] ?? null) ? $call['function'] : '';
        $guard = $this->registry->asksFor($name);

        // Guarded by an attribute, or reading a form: not run at all. Whether
        // a visitor may is for the visitor, and so is what they typed.
        if ($guard !== null) {
            return ['json' => $this->encode(['id' => $id, 'visitor' => [$guard]]), 'last' => false];
        }

        ['status' => $status, 'json' => $json, 'reads' => $reads] = $this->run([
            'function' => $name,
            'args' => $call['args'] ?? [],
        ]);

        if ($reads !== []) {
            // And this process is done. A singleton may have kept what it
            // worked out - a CurrentTeam holding null - and the next call
            // would read that without touching the session at all.
            return ['json' => $this->encode(['id' => $id, 'visitor' => $reads, 'last' => true]), 'last' => true];
        }

        $head = $this->encode(['id' => $id, 'status' => $status]);

        return ['json' => $json === '{}' ? $head : substr($head, 0, -1).','.substr($json, 1), 'last' => false];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array{status: int, json: string, reads: list<string>}
     */
    private function run(array $body): array
    {
        $locale = $this->app->getLocale();

        $this->stand();

        try {
            ['status' => $status, 'json' => $json] = $this->app->make(HostCallDispatcher::class)->forBuild()->respond($body);

            // A cookie has nobody to go to, and a page stored without it
            // would never set it again.
            $jar = $this->app->make('cookie');

            if ($jar instanceof CookieJar && $jar->getQueuedCookies() !== []) {
                $this->visitor->read('a cookie');
                $jar->flushQueuedCookies();
            }

            if (isset(self::NEEDS_SOMEONE[$status])) {
                $this->visitor->read(self::NEEDS_SOMEONE[$status]);
            }

            return ['status' => $status, 'json' => $json, 'reads' => $this->visitor->reads()];
        } finally {
            $this->visitor->forget();
            $this->app->setLocale($locale);
        }
    }

    /** Put a request, a session and fresh guards in place, all of them nobody's. */
    private function stand(): void
    {
        $this->app->forgetScopedInstances();
        Facade::clearResolvedInstances();

        $session = new WatchedSession($this->visitor, (string) config('session.cookie', 'laravel_session'));
        $request = WatchedRequest::to(
            rtrim((string) config('app.url', 'http://localhost'), '/').'/'.ltrim((string) config('rsc.host_call_path'), '/'),
            $this->visitor,
        );
        $request->setLaravelSession($session);

        $this->app->instance('session', new WatchedSessions($this->app, $session));
        $this->app->instance('session.store', $session);
        $this->app->instance(Store::class, $session);

        // Resolved while the app booted, with the console's request and
        // whatever session the app is configured with.
        $this->app->forgetInstance('redirect');

        if ($this->app->bound('auth')) {
            $this->app->make('auth')->forgetGuards();
        }

        $this->app->instance('request', $request);
    }

    /** @param  array<string, mixed>  $reply */
    private function encode(array $reply): string
    {
        return json_encode($reply, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
