<?php

namespace RscKit\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use RscKit\RscKitServiceProvider;

/**
 * Write a class the renderer can call, with its guards already on it.
 *
 * `make:rsc-action Orders --method=cancel --auth --can=update,Order` is the
 * class the Laravel guide shows, typed out: the attributes the registry
 * reads are the same ones written here, so a guard asked for at the prompt
 * is the guard that runs. Under app/Rsc/Actions by default - a server action
 * the build writes a "use server" stub for - or under app/Rsc with --rpc, a
 * class a server component reaches through rpc().
 *
 * --form is a public form whole: a FormRequest whose rules() are the form's
 * fields, the action taking it, and resources/js/forms/<name>.ts handing the
 * generated action to defineBlueprint - the three files the Laravel guide shows.
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
        {--form : A public form: the action takes a FormRequest, which is written too, with the form in resources/js/forms}
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
        $form = (bool) $this->option('form');

        if ($form && ($rpc || count((array) $this->option('method')) > 1)) {
            $this->components->error('A form posts to one action: --form takes at most one --method, and not --rpc.');

            return self::FAILURE;
        }

        $request = $form ? $this->requestClass($class) : null;

        $namespace = rtrim('App\\Rsc'.($rpc ? '' : '\\Actions').($segments ? '\\'.implode('\\', $segments) : ''), '\\');
        $directory = ($rpc ? app_path('Rsc') : app_path('Rsc/Actions')).($segments ? '/'.implode('/', $segments) : '');
        $path = "{$directory}/{$class}.php";

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->error("{$path} already exists. Pass --force to overwrite it.");

            return self::FAILURE;
        }

        $this->files->ensureDirectoryExists($directory);
        if ($request !== null) {
            $this->writeRequest($request);
        }

        $this->files->put($path, $this->render($namespace, $class, $rpc, $request));

        $this->components->info(sprintf('%s [%s] created.', $rpc ? 'rpc class' : 'Server action', $path));

        // A cached map - `optimize` was run here - would not have the class
        // in it, and the registry reads only the map while one exists. So it
        // is written again, with the class loaded by path first for the same
        // reason as the manifest below.
        if (is_file(RscKitServiceProvider::callablesCachePath())) {
            require_once $path;

            $this->call('rsc:cache');
        }

        if ($rpc) {
            $this->line('  Reach it from a server component: '.$this->rpcExamples($class));

            // Its names are rpc()'s type, so the manifest is written for it too.
            require_once $path;
            $this->call('rsc:host-manifest');

            return self::SUCCESS;
        }

        if ($request !== null) {
            $this->writeForm($class);
        } else {
            $this->line('  '.$this->stubExamples($class));
        }

        // The manifest is what the build reads and the "use server" stub is
        // generated from, so a class that is not in it does not exist to the
        // app. Written here rather than left to the dev script's next run: a
        // dev server watches the file and starts again when it changes, so
        // the stub is importable the moment this command returns.
        //
        // Loaded by path first. In an application Composer's autoloader finds
        // the class by name - unless the autoloader is classmap-authoritative,
        // as an optimised production dump is, in which case a class written a
        // moment ago is not in the map and discovery would skip it silently.
        // The request too: its rules() are the form's fields in the manifest.
        if ($request !== null && $this->files->exists($requestPath = app_path("Http/Requests/{$request}.php"))) {
            require_once $requestPath;
        }

        require_once $path;

        $this->call('rsc:host-manifest');

        return self::SUCCESS;
    }

    /** The class source. */
    private function render(string $namespace, string $class, bool $rpc, ?string $request = null): string
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

        if ($request !== null) {
            $uses[] = "App\\Http\\Requests\\{$request}";
        }

        $uses = array_unique($uses);
        sort($uses);

        $body = $invokable
            ? $this->method('__invoke', $rpc, $revalidate, $request)
            : implode("\n\n", array_map(fn (string $m) => $this->method($m, $rpc, $revalidate, $request), $methods));

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

    private function method(string $name, bool $rpc, ?string $revalidate, ?string $request = null): string
    {
        if ($request !== null) {
            // A form's action: the request is validated before it runs, and
            // its 422 lands under the fields.
            $lines = [
                "    public function {$name}({$request} \$request): void",
                '    {',
                '        // $request->validated()',
            ];

            if ($revalidate !== null) {
                $lines[] = '';
                $lines[] = "        Rsc::revalidate('{$revalidate}');";
            }

            $lines[] = '    }';

            return implode("\n", $lines);
        }

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

    /** SendContact for Contact --method=send, ContactRequest for an invokable Contact. */
    private function requestClass(string $class): string
    {
        $method = ((array) $this->option('method'))[0] ?? null;

        return $method === null ? "{$class}Request" : Str::studly((string) $method).$class;
    }

    /** The FormRequest, unless the app has one by that name already. */
    private function writeRequest(string $request): void
    {
        $path = app_path("Http/Requests/{$request}.php");

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->warn("{$path} already exists; the action takes it as it is.");

            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, <<<PHP
        <?php

        namespace App\\Http\\Requests;

        use Illuminate\\Foundation\\Http\\FormRequest;

        /**
         * The form's fields, in the order of rules(). required, the type rules,
         * email, url, date, min, max, size, between and in become the browser's
         * checks; every rule is Laravel's, and its 422 lands under the field.
         */
        class {$request} extends FormRequest
        {
            public function authorize(): bool
            {
                return true; // a public form: anyone may send it
            }

            /**
             * @return array<string, mixed>
             */
            public function rules(): array
            {
                return [
                    'name' => ['required', 'string', 'min:2'],
                    'email' => ['required', 'email'],
                ];
            }
        }

        PHP);

        $this->components->info("Form request [{$path}] created.");
    }

    /** resources/js/forms/<name>.ts, handing the generated action to defineBlueprint. */
    private function writeForm(string $class): void
    {
        $method = ((array) $this->option('method'))[0] ?? null;
        $action = $method === null ? lcfirst($class) : lcfirst($class).ucfirst(Str::camel((string) $method));
        $name = Str::kebab($class);
        $export = Str::camel($class);
        $path = resource_path("js/forms/{$name}.ts");

        if ($this->files->exists($path) && ! $this->option('force')) {
            $this->components->warn("{$path} already exists; it was left as it is.");

            return;
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, <<<TS
        import { defineBlueprint } from '@rsc-kit/press/forms'
        import { shadcn } from '@rsc-kit/press/shadcn'
        import { {$action} } from '../server-actions.generated'

        // The fields are the request's rules(), and every check is Laravel's.
        // Place it in any page: <{$export}.Form />, or hand it to a resource as its form.
        export const {$export} = defineBlueprint({
          name: '{$name}',
          actions: { create: {$action} },
          theme: shadcn,
        })

        TS);

        $this->components->info("Form [{$path}] created. Place it in a page: <{$export}.Form />");
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
