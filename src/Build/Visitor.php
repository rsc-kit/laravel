<?php

namespace RscKit\Build;

use Closure;

/**
 * What a call made by the build reached for that only a visitor has.
 *
 * The build has no visitor, so a function that reads the session, a cookie or
 * a header got nothing back - an answer for a guest, which is not an answer to
 * store for everyone. Noted rather than thrown: an exception is something the
 * function may catch, and what it did with an empty session is not worth
 * keeping either way.
 */
class Visitor
{
    /** @var array<string, true> */
    private array $reads = [];

    private int $paused = 0;

    public function read(string $what): void
    {
        if ($this->paused === 0) {
            $this->reads[$what] = true;
        }
    }

    /** @return list<string> */
    public function reads(): array
    {
        return array_keys($this->reads);
    }

    public function forget(): void
    {
        $this->reads = [];
    }

    /**
     * Run something that reads the request without asking who sent it: which
     * host and path a url is built from are the endpoint's own.
     *
     * @template T
     *
     * @param  Closure(): T  $run
     * @return T
     */
    public function notAsking(Closure $run): mixed
    {
        $this->paused++;

        try {
            return $run();
        } finally {
            $this->paused--;
        }
    }
}
