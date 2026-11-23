<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use RscKit\CallableRegistry;
use RscKit\RouteMiddleware;
use RscKit\Support\ActionManifest;
use RscKit\Support\HostSources;
use RscKit\Support\HostTypes;
use RscKit\Versions;

/**
 * Hands the build what this backend offers the app, as rsc-host.json:
 *
 *     { "actions": { "orders": { "create": "Orders.create" } }, "functions": ["Orders.create", "Orders.recent"] }
 *
 * `actions` are the server actions, by module - one per class - and export:
 * the build writes each module as server-actions/orders.ts, a "use server"
 * module exporting `create`.
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
        $callables = array_filter(
            [...CallableRegistry::discover(app_path('Rsc')), ...$registry->all()],
            fn (string $name) => $name !== RouteMiddleware::FUNCTION && $name !== Versions::FUNCTION,
            ARRAY_FILTER_USE_KEY,
        );

        $functions = array_keys($callables);

        sort($functions);

        // What each one takes and returns, so the app's rpc() and the action
        // stubs are typed from the PHP signatures.
        ['types' => $types, 'defs' => $defs] = HostTypes::describe($callables);

        // As objects even when empty: json_encode writes an empty PHP array
        // as [], and the build reads a map of modules, each a map of exports.
        $json = json_encode(
            [
                'actions' => (object) array_map(fn (array $exports) => (object) $exports, $actions),
                'functions' => $functions,
                'types' => (object) $types,
                'defs' => (object) $defs,
                // What can be checked without running: a form request's
                // rules, filtered to the fields a form names, before submit.
                'validates' => CallableRegistry::validating($callables),
                // Where each is written, for the editor: the build maps
                // rpc('Orders.recent') to its line.
                'sources' => (object) HostSources::describe($callables),
            ],
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
        );

        if ($this->option('print')) {
            $this->line($json);

            return self::SUCCESS;
        }

        file_put_contents(base_path('rsc-host.json'), $json."\n");

        $this->info(sprintf('Wrote %d action(s) and %d function(s) to rsc-host.json', array_sum(array_map('count', $actions)), count($functions)));

        return self::SUCCESS;
    }
}
