<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Sets an existing Laravel application up to render server components.
 *
 * Two halves, because the work is genuinely in two places. This side owns the
 * config and the shared secret. The other side — the Vite config, the route
 * tree, the renderer, package.json — is owned by `rsc-kit init`, which this
 * command runs rather than reimplements.
 *
 * That split is the whole design. Those templates already exist, they are
 * already tested, and they change whenever the engine does; a PHP copy of them
 * would be a second implementation of the same files, drifting from the engine
 * that has to read them. One implementation, two doors.
 *
 * Nothing existing is overwritten by either half. Files that are already there
 * are left alone and the exact edit is printed instead.
 */
class InstallCommand extends Command
{
    protected $signature = 'rsc:install
        {--force : Overwrite config/rsc.php if it is already published}
        {--skip-js : Do only the PHP half, and print the command for the rest}';

    protected $description = 'Set this application up to render React Server Components';

    public function handle(): int
    {
        $this->components->info('Setting up rsc-kit');

        $this->publishConfig();
        $this->ensureSecret();

        if ($this->option('skip-js')) {
            $this->components->warn('Skipped the JavaScript half. Run it yourself:');
            $this->line('  '.$this->jsCommand());

            return self::SUCCESS;
        }

        if (! $this->runJavaScriptHalf()) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Done. Next:');
        $this->line('  npm install    (or bun install)');
        $this->line('  npm run dev    your asset pipeline AND the renderer');
        $this->newLine();
        $this->line('  Then open this application at its own url. Laravel hands any request');
        $this->line('  it does not route to the renderer, so pages work with nothing else set.');
        $this->newLine();
        $this->line('  That needs a server with more than one worker — Herd, Valet, FPM and');
        $this->line('  Octane all are. `php artisan serve` is not, and cannot render a page');
        $this->line('  here; open the renderer directly instead, at http://localhost:5173.');
        $this->newLine();

        return self::SUCCESS;
    }

    /**
     * config/rsc.php, so the settings are readable and editable in the app.
     */
    private function publishConfig(): void
    {
        $target = config_path('rsc.php');

        if (file_exists($target) && ! $this->option('force')) {
            $this->components->twoColumnDetail('config/rsc.php', '<fg=gray>already published</>');

            return;
        }

        $this->callSilently('vendor:publish', [
            '--tag' => 'rsc-config',
            '--force' => true,
        ]);

        $this->components->twoColumnDetail('config/rsc.php', '<fg=green>published</>');
    }

    /**
     * The shared secret, in .env and named in .env.example.
     *
     * Generated rather than asked for, and never regenerated: the renderer is
     * configured with the same value, so changing it here turns every host
     * call into a 401 that looks like the application refusing its own data.
     *
     * .env.example gets the name with no value, because it is committed. A
     * secret in a repository is not a secret, and a placeholder that looks
     * usable is worse than none — someone will ship it.
     */
    private function ensureSecret(): void
    {
        $env = base_path('.env');

        if (! file_exists($env)) {
            $this->components->warn('No .env file, so no secret was written. Add RSC_HOST_CALL_SECRET yourself.');

            return;
        }

        $contents = file_get_contents($env);

        if (preg_match('/^RSC_HOST_CALL_SECRET=.+$/m', $contents) === 1) {
            $this->components->twoColumnDetail('RSC_HOST_CALL_SECRET', '<fg=gray>already set</>');

            return;
        }

        $secret = base64_encode(random_bytes(32));

        // Appended with its own heading rather than slotted in beside anything:
        // this file is hand-edited, and the one thing an installer must not do
        // to it is move somebody's lines around.
        file_put_contents($env, rtrim($contents, "\n")."\n\n"
            ."# Shared with the RSC renderer. Both processes read this, and a\n"
            ."# mismatch answers every host call with 403.\n"
            ."RSC_HOST_CALL_SECRET=\"{$secret}\"\n");

        $this->components->twoColumnDetail('RSC_HOST_CALL_SECRET', '<fg=green>generated in .env</>');

        $example = base_path('.env.example');

        if (file_exists($example) && ! str_contains((string) file_get_contents($example), 'RSC_HOST_CALL_SECRET')) {
            file_put_contents($example, rtrim((string) file_get_contents($example), "\n")."\n\nRSC_HOST_CALL_SECRET=\n");

            $this->components->twoColumnDetail('.env.example', '<fg=green>named the secret</>');
        }
    }

    /**
     * Run `rsc-kit init`, which owns every file the JavaScript side needs.
     */
    private function runJavaScriptHalf(): bool
    {
        $this->newLine();

        $process = Process::fromShellCommandline($this->jsCommand(), base_path(), timeout: 300);

        // A tty when there is one, so the generator's own questions are asked
        // rather than answered on the user's behalf. Not every environment
        // has one — CI, a queued command, a container — and asking for one
        // there throws, so the fallback answers everything with its defaults.
        if ($this->input->isInteractive() && Process::isTtySupported()) {
            try {
                $process->setTty(true);
            } catch (\RuntimeException) {
                // No tty after all. The output below still reaches the user.
            }
        }

        $process->run(function ($type, $line) {
            $this->output->write($line);
        });

        if ($process->isSuccessful()) {
            return true;
        }

        $this->components->error('The JavaScript half did not finish. Run it yourself:');
        $this->line('  '.$this->jsCommand());

        return false;
    }

    /**
     * What to run, and what to run it with.
     *
     * bunx when Bun is here, npx otherwise. Neither needs the package
     * installed first, which matters: this is the command that installs it.
     */
    private function jsCommand(): string
    {
        $runner = $this->hasBun() ? 'bunx' : 'npx --yes';

        $arguments = [
            'init',
            // Detected anyway — artisan and composer.json are both here — but
            // said explicitly, because this command knows the answer and a
            // detection that silently guesses wrong is worse than one that is
            // never consulted.
            '--host=laravel',
            '--backend='.escapeshellarg((string) config('app.url')),
        ];

        if (! $this->input->isInteractive()) {
            $arguments[] = '--yes';
        }

        return $runner.' rsc-kit '.implode(' ', $arguments);
    }

    private function hasBun(): bool
    {
        $which = new Process(['which', 'bun']);
        $which->run();

        return $which->isSuccessful();
    }
}
