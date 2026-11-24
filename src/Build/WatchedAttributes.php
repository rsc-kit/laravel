<?php

namespace RscKit\Build;

use Symfony\Component\HttpFoundation\ParameterBag;

/** $request->attributes: where a middleware leaves what it worked out about the visitor. */
class WatchedAttributes extends ParameterBag
{
    use WatchesReads;

    public function __construct(Visitor $visitor)
    {
        parent::__construct();

        $this->watch($visitor, 'a request attribute', ['parameters']);
    }
}
