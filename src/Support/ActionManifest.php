<?php

namespace RscKit\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;

/**
 * Maps the app's PHP action classes to the JS function names generated for them.
 *
 * Shared by `rsc:action-manifest` and the build, so the names the build writes
 * are always the names the manifest reports.
 */
class ActionManifest
{
    /**
     * Every public instance method of every class in the actions dir, keyed by
     * the JS function name it is exposed as.
     *
     * @return array<string, string>
     */
    public static function discover(): array
    {
        $directory = config('rsc.actions_dir', app_path('Rsc/Actions'));

        if ($directory === null || ! is_dir($directory)) {
            return [];
        }

        $actions = [];

        foreach (self::phpFilesUnder($directory) as $file) {
            $className = self::resolveClassName($file);

            if ($className === null || ! class_exists($className)) {
                continue;
            }

            $reflection = new ReflectionClass($className);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $shortName = $reflection->getShortName();
            $baseName = preg_replace('/Callable$/', '', $shortName);

            foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                // Magic methods are PHP's, not the app's: __call would take any name
                // and any arguments from a browser, __toString and __destruct
                // are not actions. __invoke is the one that is.
                if ($method->isStatic() || $method->isConstructor() || (str_starts_with($method->getName(), '__') && $method->getName() !== '__invoke')) {
                    continue;
                }

                $methodName = $method->getName();

                if ($methodName === '__invoke') {
                    $jsName = lcfirst($baseName);
                    $phpCallable = $shortName;
                } else {
                    $jsName = lcfirst($baseName).ucfirst($methodName);
                    $phpCallable = "{$shortName}.{$methodName}";
                }

                $actions[$jsName] = $phpCallable;
            }
        }

        return $actions;
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
     * Resolve a fully-qualified class name from a PHP file path.
     */
    private static function resolveClassName(string $filePath): ?string
    {
        $contents = file_get_contents($filePath);

        if ($contents === false) {
            return null;
        }

        if (preg_match('/namespace\s+([^;]+);/', $contents, $nsMatch)
            && preg_match('/class\s+(\w+)/', $contents, $classMatch)) {
            return $nsMatch[1].'\\'.$classMatch[1];
        }

        return null;
    }
}
