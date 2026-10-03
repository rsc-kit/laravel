<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use RscKit\Versions;

/**
 * Delete the rsc_versions rows nobody has changed in a while.
 *
 * A row is kept for every name that ever changed - one per order, job or
 * restoration, for ever - so schedule this:
 *
 *     Schedule::command('rsc:prune-versions')->daily();
 *
 * Always safe: a version is the time a name last changed and never comes
 * round again, so a tab still holding a pruned name refreshes once. In cache
 * mode there is nothing to do; keys expire on their own.
 */
class PruneVersionsCommand extends Command
{
    protected $signature = 'rsc:prune-versions {--days= : Delete names not changed in this many days (default rsc.versions_keep_days)}';

    protected $description = 'Delete refreshOn versions nobody has changed in a while';

    public function handle(Versions $versions): int
    {
        if (! $versions->inTable()) {
            $this->components->info('Versions are in the cache, and expire on their own after rsc.versions_keep_days.');

            return self::SUCCESS;
        }

        $days = $this->option('days');
        $deleted = $versions->prune($days === null ? null : (int) $days);

        $this->components->info("Deleted {$deleted} name(s) not changed in ".($days ?? config('rsc.versions_keep_days', 30)).' days.');

        return self::SUCCESS;
    }
}
