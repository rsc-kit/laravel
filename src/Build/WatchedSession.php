<?php

namespace RscKit\Build;

use Illuminate\Session\NullSessionHandler;
use Illuminate\Session\Store;

/** A session nobody has: empty, and every look into it noted. */
class WatchedSession extends Store
{
    use WatchesReads;

    public function __construct(Visitor $visitor, string $name)
    {
        parent::__construct($name, new NullSessionHandler);

        $this->watch($visitor, 'the session', ['attributes', 'id', 'started']);
    }
}
