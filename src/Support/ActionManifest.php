<?php

namespace RscKit\Support;

use FilesystemIterator;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Maps the app's PHP action classes to the JS modules generated for them.
 *
 * One module per class, at its path under the actions directory in kebab
 * case: App\Rsc\Actions\Billing\Invoices is `billing/invoices`, its `create`
 * method that module's `create` export, and an `__invoke` its default export.
 * The build writes each as `server-actions/billing/invoices.ts`.
 */
class ActionManifest
{
    /**
     * Every public instance method of every class in the actions dir: by
     * module, then by the name the module exports it under, the function the
     * endpoint answers it with.
     *
     * @return array<string, array<string, string>>
     */
    public static function discover(): array
    {
        $directory = config('rsc.actions_dir', app_path('Rsc/Actions'));

        if ($directory === null || ! is_dir($directory)) {
            return [];
        }

        $actions = [];
        $owners = [];
        $answeredBy = [];

        foreach (self::phpFilesUnder($directory) as $file) {
            $className = self::classIn($file);

            if ($className === null || ! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $shortName = $reflection->getShortName();
            $module = self::moduleFor($directory, $file, preg_replace('/Callable$/', '', $shortName));

            if (isset($actions[$module])) {
                throw new RuntimeException(sprintf(
                    '%s and %s would both be the module server-actions/%s. Rename one.',
                    $owners[$module], $className, $module,
                ));
            }

            $owners[$module] = $className;
            $actions[$module] = [];

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Magic methods are PHP's, not the app's: __call would take any name
                // and any arguments from a browser, __toString and __destruct
                // are not actions. __invoke is the one that is.
                if ($method->isStatic() || $method->isConstructor() || (str_starts_with($method->getName(), '__') && $method->getName() !== '__invoke')) {
                    continue;
                }

                $methodName = $method->getName();
                [$export, $callable] = $methodName === '__invoke'
                    ? ['default', $shortName]
                    : [$methodName, "{$shortName}.{$methodName}"];

                // The endpoint answers a name with the first class that has
                // it, so a second would post to the first one's method.
                if (isset($answeredBy[$callable])) {
                    throw new RuntimeException(sprintf(
                        '%s and %s are both %s to the endpoint, which would answer both with the first. Rename one.',
                        $answeredBy[$callable], $className, $callable,
                    ));
                }

                if (isset($actions[$module][$export])) {
                    throw new RuntimeException("{$className} has both __invoke and a method named default: the module exports one default. Rename the method.");
                }

                $answeredBy[$callable] = $className;
                $actions[$module][$export] = $callable;
            }

            if ($actions[$module] === []) {
                unset($actions[$module]);
            }
        }

        return $actions;
    }

    /**
     * A class's module: its folder under the actions dir and its name, each
     * in kebab case - Billing/SendInvoice is billing/send-invoice.
     */
    private static function moduleFor(string $directory, string $file, string $baseName): string
    {
        $folder = trim(str_replace('\\', '/', substr(dirname($file), strlen(rtrim($directory, '/\\')))), '/');
        $parts = [...($folder === '' ? [] : explode('/', $folder)), $baseName];

        return implode('/', array_map(
            fn (string $part) => trim(preg_replace('/[^a-z0-9]+/', '-', Str::kebab($part)), '-'),
            $parts,
        ));
    }

    /**
     * Every PHP file under a directory, subdirectories included, in a stable
     * order.
     *
     * Recursive because `make:rsc-action Billing/Invoices` nests the class
     * under Billing/, and a glob of the top level alone never found it: the
     * command said "created", the manifest said nothing, and the stub was
     * simply not there to import.
     *
     * @return list<string>
     */
    public static function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The fully-qualified name of the class a PHP file declares.
     *
     * Read with PHP's own tokenizer rather than a pattern. A pattern for
     * `class Name` matched the first place those words met, and a docblock
     * saying "this class handles refunds" is such a place: discovery went
     * looking for Refunds\handles, found nothing, and the class the file
     * declared was skipped without a word. A token is only a T_CLASS where
     * PHP would read one, so comments and strings cannot stand in for it -
     * and one after `::` (Foo::class) or `new` (an anonymous class) is not a
     * declaration.
     *
     * Shared with the registry, so the actions the build is told about and
     * the functions the endpoint answers are found the same way.
     */
    public static function classIn(string $filePath): ?string
    {
        $contents = @file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        $tokens = array_values(array_filter(
            token_get_all($contents),
            fn ($token) => ! is_array($token) || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
        ));

        $namespace = '';

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $next = $tokens[$i + 1] ?? null;

                if (is_array($next) && in_array($next[0], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $namespace = $next[1];
                }

                continue;
            }

            if ($token[0] !== T_CLASS) {
                continue;
            }

            $previous = $tokens[$i - 1] ?? null;

            if (is_array($previous) && in_array($previous[0], [T_DOUBLE_COLON, T_NEW], true)) {
                continue;
            }

            $name = $tokens[$i + 1] ?? null;

            if (is_array($name) && $name[0] === T_STRING) {
                return $namespace === '' ? $name[1] : $namespace.'\\'.$name[1];
            }
        }

        return null;
    }
}
