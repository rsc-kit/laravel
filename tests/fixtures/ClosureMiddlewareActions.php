<?php

/*
 * Laravel's #[Middleware] takes a closure as readily as a name, and a closure
 * can only be written in an attribute from PHP 8.5 - so this lives in a file
 * of its own, loaded only where it parses.
 */

namespace RscTestClosureMiddleware;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Routing\Attributes\Controllers\Middleware;

class ClosureMiddlewareActions
{
    #[Middleware(static function ($request, $next) {
        throw new AuthorizationException('The closure said no.');
    })]
    public function refused(): string
    {
        return 'ran';
    }

    #[Middleware(static function ($request, $next) {
        return $next($request);
    })]
    #[Middleware('rsc-test-passes')]
    #[Middleware('rsc-test-passes')]
    public function allowed(): string
    {
        return 'ran';
    }
}
