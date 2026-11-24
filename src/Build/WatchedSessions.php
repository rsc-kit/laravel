<?php

namespace RscKit\Build;

use Illuminate\Contracts\Container\Container;
use Illuminate\Session\SessionManager;
use Illuminate\Session\Store;

/** The session manager whose every driver is the one session nobody has. */
class WatchedSessions extends SessionManager
{
    public function __construct(Container $container, private Store $store)
    {
        parent::__construct($container);
    }

    public function driver($driver = null): Store
    {
        return $this->store;
    }
}
