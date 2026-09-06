<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use RscKit\Support\ActionManifest;

/**
 * Hands the app's server actions to the build.
 *
 * Discovery has to happen here: reflection through Composer's autoloader finds
 * what a class inherits from its parents and traits, and a JS reimplementation
 * could only regex the source and would silently miss every inherited action.
 *
 * The handoff is just a map of names, so it travels as a file any language can
 * write. The Vite plugin reads it from the project root and generates the
 * "use server" stubs the app imports:
 *
 *     php artisan rsc:action-manifest
 *     vite build
 *
 * Run it before every build. A stale file names a method that has since been
 * renamed, and nothing fails until the browser calls it.
 */
class RscActionManifestCommand extends Command
{
    protected $signature = 'rsc:action-manifest {--print : Write to stdout instead of the file}';

    protected $description = 'Write the server action map the RSC build reads';

    public function handle(): int
    {
        $json = json_encode(ActionManifest::discover(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);

        if ($this->option('print')) {
            $this->line($json);

            return self::SUCCESS;
        }

        $path = base_path('rsc-host-actions.json');

        file_put_contents($path, $json."\n");

        $this->info('Wrote '.count(ActionManifest::discover()).' action(s) to rsc-host-actions.json');

        return self::SUCCESS;
    }
}
