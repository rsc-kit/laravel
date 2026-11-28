<?php

namespace RscKit\Support;

use RuntimeException;

/**
 * Where the app's callables live, from config('rsc.dir') - app/Rsc unless an
 * app prefers, say, app/Http/Rsc.
 *
 * The one place that answers it: discovery, the cache, the manifest and
 * make:rsc-action all ask here, so moving the folder is one config value
 * and not a hunt for every app_path('Rsc'). A callable's name is its class's
 * short name, so moving the folder renames nothing a page calls.
 */
class RscPaths
{
    /** Every class the renderer can call, server actions included. */
    public static function root(): string
    {
        return rtrim((string) (config('rsc.dir') ?: app_path('Rsc')), '/');
    }

    /** The server actions, the classes the build writes a "use server" stub for. */
    public static function actions(): string
    {
        return rtrim((string) (config('rsc.actions_dir') ?: self::root().'/Actions'), '/');
    }

    /**
     * The namespace of a class written under $directory, read from where it
     * sits in app/: app/Http/Rsc is App\Http\Rsc. A folder outside app/ has
     * no namespace this can know, so it is refused rather than guessed.
     */
    public static function namespaceFor(string $directory): string
    {
        $app = rtrim(app_path(), '/');
        $directory = rtrim($directory, '/');

        if ($directory !== $app && ! str_starts_with($directory, $app.'/')) {
            throw new RuntimeException("[{$directory}] is outside app/, so its classes have no namespace to write. Set rsc.dir to a folder under app/.");
        }

        $relative = trim(substr($directory, strlen($app)), '/');

        return rtrim(app()->getNamespace().str_replace('/', '\\', $relative), '\\');
    }
}
