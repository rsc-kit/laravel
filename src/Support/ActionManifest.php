<?php

namespace RscKit\Support;

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

        $files = glob($directory.'/*.php');

        if ($files === false) {
            return [];
        }

        $actions = [];

        foreach ($files as $file) {
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
                if ($method->isStatic() || $method->isConstructor()) {
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
