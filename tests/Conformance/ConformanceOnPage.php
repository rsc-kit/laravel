<?php

namespace Tests\Conformance;

use Illuminate\Routing\Attributes\Controllers\Middleware;

/** An action guarded as an app guards one: its guard judges the page it was posted from. */
class ConformanceOnPage
{
    #[Middleware('conformance-page')]
    public function __invoke(): string
    {
        return 'ok';
    }
}
