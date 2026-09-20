<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * Write a class the renderer can call, with its guards already on it.
 *
 * `make:rsc-action Orders --method=cancel --auth --can=update,Order` is the
 * class the Laravel guide shows, typed out: the attributes the registry
 * reads are the same ones written here, so a guard asked for at the prompt
 * is the guard that runs. Under app/Rsc/Actions by default - a server action
 * the build writes a "use server" stub for - or under app/Rsc with --rpc, a
 * class a server component reaches through rpc().
 */
class MakeActionCommand extends Command
{
    protected $signature = 'make:rsc-action
        {name : The class name, StudlyCase; a slash nests it (Billing/Invoices)}
        {--rpc : A class for rpc() under app/Rsc, rather than a server action under app/Rsc/Actions}
        {--method=* : A method per call; none means the class is invokable}
        {--auth : Require a signed-in visitor (#[Authenticated])}
        {--can=* : A policy check, ability or ability,Model (#[Can])}
        {--middleware=* : A route middleware by name (#[Middleware])}
        {--revalidate= : What the action makes stale, a section or page name}
        {--force : Overwrite the file if it exists}';

    protected $description = 'Make a class the renderer can call - a server action, or one for rpc()';

    public function __construct(private Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = str_replace('\\', '/', trim((string) $this->argument('name'), '/'));
        $segments = array_map(fn (string $s) => Str::studly($s), explode('/', $name));
        $class = array_pop($segments);
        $rpc = (bool) $this->option('rpc');

        $namespace = rtrim('App\\Rsc'.($rpc ? '' : '\\Actions').($segments ? '\\'.implode('\\', $segments) : ''), '\\');
        $directory = ($rpc ? app_path('Rsc') : app_path('Rsc/Actions')).($segments ? '/'.implode('/', $segments) : '');
        $path = "{$directory}/{$class}.php";

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->error("{$path} already exists. Pass --force to overwrite it.");

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists($directory);
        $this->files->put($path, $this->render($namespace, $class, $rpc));

        $this->components->info(sprintf('%s [%s] created.', $rpc ? 'rpc class' : 'Server action', $path));

        if ($rpc) {
            $this->line('  Reach it from a server component: '.$this->rpcExamples($class));

            return self::SUCCESS;
        }

        $this->line('  '.$this->stubExamples($class));

        // The map is what the build reads and the "use server" stub is
        // generated from, so a class that is not in it does not exist to the
        // app. Written here rather than left to the dev script's next run: a
        // dev server watches the file and starts again when it changes, so
        // the stub is importable the moment this command returns.
        //
        // Loaded by path first. In an application Composer's autoloader finds
        // the class by name - unless the autoloader is classmap-authoritative,
        // as an optimised production dump is, in which case a class written a
        // moment ago is not in the map and discovery would skip it silently.
        require_once $path;

        $this->call('rsc:action-manifest');

        return self::SUCCESS;
    }

    /** The class source. */
    private function render(string $namespace, string $class, bool $rpc): string
    {
        $methods = array_values(array_filter(array_map(
            fn ($m) => Str::camel((string) $m),
            (array) $this->option('method'),
        )));
        $invokable = $methods === [];
        $revalidate = $this->option('revalidate') ? (string) $this->option('revalidate') : null;

        $uses = [];
        $attributes = [];

        if ($this->option('auth')) {
            $uses[] = 'RscKit\Attributes\Authenticated';
            $attributes[] = '#[Authenticated]';
        }

        foreach ((array) $this->option('can') as $can) {
            [$ability, $model] = array_pad(explode(',', (string) $can, 2), 2, null);
            $ability = trim((string) $ability);
            $model = $model !== null ? trim($model) : null;

            $uses[] = 'RscKit\Attributes\Can';

            if ($model !== null && $model !== '') {
                $model = Str::studly($model);
                $uses[] = "App\\Models\\{$model}";
                $attributes[] = "#[Can('{$ability}', {$model}::class)]";
            } else {
                $attributes[] = "#[Can('{$ability}')]";
            }
        }

        foreach ((array) $this->option('middleware') as $middleware) {
            $uses[] = 'Illuminate\Routing\Attributes\Controllers\Middleware';
            $attributes[] = "#[Middleware('".trim((string) $middleware)."')]";
        }

        if ($revalidate !== null && ! $rpc) {
            $uses[] = 'RscKit\Rsc';
        }

        $uses = array_unique($uses);
        sort($uses);

        $body = $invokable
            ? $this->method('__invoke', $rpc, $revalidate)
            : implode("\n\n", array_map(fn (string $m) => $this->method($m, $rpc, $revalidate), $methods));

        $doc = $rpc
            ? " * Reached from a server component as rpc('{$class}".($invokable ? "'" : ".method'").").\n *\n * Public methods are what the renderer can call; constructor injection works;\n * auth()->user() is the visitor the page is being rendered for."
            : " * A server action. The build writes a \"use server\" export per public method,\n * named ".($invokable ? lcfirst($class) : lcfirst($class).'Method').", that a client component imports and calls.\n *\n * Throw a ValidationException, AuthenticationException or AuthorizationException\n * and the refusal reaches the form as itself; Rsc::revalidate() names what a\n * successful call made stale, and the answer carries it re-rendered.";

        return "<?php\n\n"
            ."namespace {$namespace};\n\n"
            .($uses ? implode("\n", array_map(fn (string $u) => "use {$u};", $uses))."\n\n" : '')
            ."/**\n{$doc}\n */\n"
            .($attributes ? implode("\n", $attributes)."\n" : '')
            ."class {$class}\n"
            ."{\n"
            .$body."\n"
            ."}\n";
    }

    private function method(string $name, bool $rpc, ?string $revalidate): string
    {
        $lines = [
            "    public function {$name}(): mixed",
            '    {',
            '        //',
        ];

        if ($revalidate !== null && ! $rpc) {
            $lines[] = '';
            $lines[] = "        Rsc::revalidate('{$revalidate}');";
        }

        $lines[] = '';
        $lines[] = '        return null;';
        $lines[] = '    }';

        return implode("\n", $lines);
    }

    private function rpcExamples(string $class): string
    {
        $methods = array_map(fn ($m) => Str::camel((string) $m), (array) $this->option('method'));

        if ($methods === []) {
            return "await rpc('{$class}')";
        }

        return implode(', ', array_map(fn (string $m) => "await rpc('{$class}.{$m}')", $methods));
    }

    private function stubExamples(string $class): string
    {
        $methods = array_map(fn ($m) => Str::camel((string) $m), (array) $this->option('method'));
        $names = $methods === []
            ? [lcfirst($class)]
            : array_map(fn (string $m) => lcfirst($class).ucfirst($m), $methods);

        return 'Import { '.implode(', ', $names).' } from the generated server-actions module in a client component.';
    }
}
