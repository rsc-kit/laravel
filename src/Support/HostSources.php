<?php

namespace RscKit\Support;

use Closure;
use ReflectionMethod;
use Throwable;

/**
 * Where each callable is written, so an editor can go from rpc('Orders.recent')
 * to the method.
 *
 * Reflection's answer, so an inherited method or a trait's is the file it is
 * really in. Only what is inside the application: the manifest may be
 * committed, and a path from this machine means nothing on another.
 */
class HostSources
{
    /**
     * @param  array<string, array{class-string, string}|class-string|Closure>  $callables
     * @return array<string, array{file: string, line: int, name?: string}>
     */
    public static function describe(array $callables): array
    {
        $sources = [];

        foreach ($callables as $name => $callable) {
            try {
                $fn = HostTypes::reflect($callable);
            } catch (Throwable) {
                continue;
            }

            $file = self::relative((string) $fn->getFileName());

            if ($file === null || $fn->getStartLine() === false) {
                continue;
            }

            $sources[$name] = ['file' => $file, 'line' => $fn->getStartLine()];

            // As the app calls it, not where it is inherited from: the class
            // the name stands for is the one worth reading in a hover.
            if ($fn instanceof ReflectionMethod) {
                $class = is_string($callable) ? $callable : $callable[0];

                $sources[$name]['name'] = "{$class}::{$fn->getName()}";
            }
        }

        ksort($sources);

        return $sources;
    }

    private static function relative(string $file): ?string
    {
        $file = str_replace('\\', '/', $file);

        // The real path too: reflection reports the file PHP loaded, which is
        // past any link the application directory is reached through.
        foreach ([base_path(), realpath(base_path()) ?: base_path()] as $root) {
            $root = rtrim(str_replace('\\', '/', $root), '/').'/';

            if (str_starts_with($file, $root)) {
                return substr($file, strlen($root));
            }
        }

        return null;
    }
}
