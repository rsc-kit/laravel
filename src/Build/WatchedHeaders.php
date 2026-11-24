<?php

namespace RscKit\Build;

use Symfony\Component\HttpFoundation\HeaderBag;

/** The headers a visitor would have sent. */
class WatchedHeaders extends HeaderBag
{
    use WatchesReads;

    /** @param  array<string, list<string|null>>  $headers */
    public function __construct(Visitor $visitor, array $headers = [])
    {
        parent::__construct($headers);

        $this->watch($visitor, 'a request header', ['headers', 'cacheControl']);
    }
}
