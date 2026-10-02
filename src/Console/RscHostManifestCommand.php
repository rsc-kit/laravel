<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use RscKit\CallableRegistry;
use RscKit\RouteMiddleware;
use RscKit\Support\ActionManifest;

/**
 * Hands the build what this backend offers the app, as rsc-host.json:
 *
 *     { "actions": { "ordersCreate": "Orders.create" }, "functions": ["Orders.create", "Orders.recent"] }
 *
 * `actions` are the server actions the build writes "use server" stubs for.
 * `functions` are the names rpc() may be called with, which the build turns
 * into a type, so a misspelt one fails the typecheck.
 *
 * Discovery has to happen here: reflection through Composer's autoloader finds
 * what a class inherits from its parents and traits, and a JS reimplementation
 * could only regex the source and would silently miss every inherited action.
 *
 * Run it as dev and every build start - rscKit({ hostManifest: { command:
 * ['php', 'artisan', 'rsc:host-manifest'] } }) does. A stale file names a
 * method that has since been renamed, and nothing fails until the browser
 * calls it.
 */
class RscHostManifestCommand extends Command
{
    protected $signature = 'rsc:host-manifest {--print : Write to stdout instead of the file}';

    protected $description = 'Write rsc-host.json, the actions and functions the RSC build reads';

    public function handle(CallableRegistry $registry): int
    {
        $actions = ActionManifest::discover();

        // Walked again rather than read from the registry alone: make:rsc-action
        // calls this a moment after writing a class the registry was built
        // without. The registry adds what the app registered by hand.
        $functions = array_values(array_unique([
            ...array_keys(CallableRegistry::discover(app_path('Rsc'))),
            ...array_filter($registry->names(), fn (string $name) => $name !== RouteMiddleware::FUNCTION),
        ]));

        sort($functions);

        // As an object even when empty: json_encode writes an empty PHP array
        // as [], and the build reads a map of actions.
        $json = json_encode(
            ['actions' => (object) $actions, 'functions' => $functions],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if ($this->option('print')) {
            $this->line($json);

            return self::SUCCESS;
        }

        file_put_contents(base_path('rsc-host.json'), $json."\n");

        $this->info(sprintf('Wrote %d action(s) and %d function(s) to rsc-host.json', count($actions), count($functions)));

        return self::SUCCESS;
    }
}
