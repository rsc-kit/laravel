<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use RscKit\CallableRegistry;
use RscKit\RscKitServiceProvider;

/**
 * Writes down what discovery finds, so production does not find it again.
 *
 * Discovery walks app/Rsc, reads and tokenizes every file, and reflects on
 * every class - and under FPM the registry is built fresh for every request,
 * so every host call paid for all of it before running the one function it
 * asked for. In production the answer only changes on deploy, which is what
 * `route:cache` and `event:cache` already assume; this is the same bargain,
 * and `php artisan optimize` runs it with theirs.
 *
 * Nothing reads the file unless this wrote it, so development - where a class
 * appears the moment make:rsc-action writes it - never sees a stale map.
 */
class RscCacheCommand extends Command
{
    protected $signature = 'rsc:cache';

    protected $description = 'Cache the functions the renderer can call, for production';

    public function handle(): int
    {
        $discovered = CallableRegistry::discover(app_path('Rsc'));
        $path = RscKitServiceProvider::callablesCachePath();

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, '<?php return '.var_export($discovered, true).';'.PHP_EOL);

        $this->components->info('Cached '.count($discovered).' callable(s).');

        return self::SUCCESS;
    }
}
