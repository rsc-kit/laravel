<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use RscKit\RscKitServiceProvider;

/** Forgets the map `rsc:cache` wrote, so discovery runs on every request again. */
class RscClearCommand extends Command
{
    protected $signature = 'rsc:clear';

    protected $description = 'Remove the cached callables file';

    public function handle(): int
    {
        @unlink(RscKitServiceProvider::callablesCachePath());

        $this->components->info('Cached callables cleared.');

        return self::SUCCESS;
    }
}
