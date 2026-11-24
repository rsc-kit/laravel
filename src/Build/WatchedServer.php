<?php

namespace RscKit\Build;

use Symfony\Component\HttpFoundation\ServerBag;

/** $request->server: the headers again, and the visitor's address. */
class WatchedServer extends ServerBag
{
    use WatchesReads;

    /** @param  array<string, mixed>  $parameters */
    public function __construct(Visitor $visitor, array $parameters = [])
    {
        parent::__construct($parameters);

        $this->watch($visitor, 'the request', ['parameters']);
    }
}
