<?php

namespace RscKit\Support;

/**
 * The renderer's route table, as Laravel route patterns.
 *
 * The build writes its table with each route as a list of segments - static,
 * a param, a catch-all - which is the same three things a Laravel pattern
 * has: a literal, `{id}`, and `{rest}` constrained to `.*`. Pages answer GET
 * and HEAD; a route.ts answers the methods it exports. Nothing here reads
 * the tree itself: the build already did, and two walks of one directory
 * are two chances to disagree.
 */
final class RendererRoutes
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return list<array{0: string, 1: list<string>, 2: list<string>}> pattern, catch-all names, methods
     */
    public static function patterns(array $manifest): array
    {
        $out = [];

        foreach ($manifest['routes'] ?? [] as $route) {
            if (! is_array($route) || ! is_array($route['segments'] ?? null)) {
                continue;
            }

            $out[] = [...self::pattern($route['segments']), ['GET', 'HEAD']];
        }

        foreach ($manifest['apis'] ?? [] as $api) {
            if (! is_array($api) || ! is_array($api['segments'] ?? null)) {
                continue;
            }

            $methods = array_values(array_filter(
                (array) ($api['methods'] ?? []),
                fn ($method) => is_string($method) && $method !== '',
            ));

            if ($methods === []) {
                continue;
            }

            if (in_array('GET', $methods, true) && ! in_array('HEAD', $methods, true)) {
                $methods[] = 'HEAD';
            }

            $out[] = [...self::pattern($api['segments']), $methods];
        }

        return $out;
    }

    /**
     * @param  list<array{type?: string, value?: string}>  $segments
     * @return array{0: string, 1: list<string>}
     */
    private static function pattern(array $segments): array
    {
        $parts = [];
        $catchAll = [];

        foreach ($segments as $segment) {
            $type = $segment['type'] ?? 'static';
            $value = (string) ($segment['value'] ?? '');

            if ($type === 'param') {
                $parts[] = '{'.$value.'}';
            } elseif ($type === 'catchAll') {
                $parts[] = '{'.$value.'}';
                $catchAll[] = $value;
            } else {
                $parts[] = $value;
            }
        }

        return ['/'.implode('/', $parts), $catchAll];
    }
}
