<?php

namespace Tests;

use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RscKit\RscKitServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            RscKitServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        // Both keys the old runtime needed are gone: there is no socket to
        // point at and nothing to enable. What a test needs now is a secret,
        // because the endpoint is not registered without one.
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));
    }
}
