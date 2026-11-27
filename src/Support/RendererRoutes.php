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
     * The names pages and route.ts files declare, by pattern.
     *
     * `export const name = 'verification.verify'` in the file, carried by
     * the build as `routeName`: the name Laravel's own code asks for - a
     * notification's link, a middleware's redirect - given to the page that
     * answers it, wherever its folder is. In the file it names, because a
     * route has one name and a middleware.ts covers the folders below it.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string> pattern => name
     */
    public static function declaredNames(array $manifest): array
    {
        $out = [];

        foreach ([...($manifest['routes'] ?? []), ...($manifest['apis'] ?? [])] as $route) {
            if (! is_array($route) || ! is_array($route['segments'] ?? null)) {
                continue;
            }

            $name = $route['routeName'] ?? null;

            if (is_string($name) && $name !== '') {
                $out[self::pattern($route['segments'])[0]] = $name;
            }
        }

        return $out;
    }

    /**
     * The route name each page answers to, by its pattern.
     *
     * Its path with dots for slashes, a parameter by its own name:
     * app/login/page.tsx is `login`, app/merchant/onboarding is
     * `merchant.onboarding`, app/orders/[id] is `orders.id`. This is what lets
     * Laravel's own `route('login')` - which `auth` sends a guest to - find a
     * login page the React tree has. The root page has no segment to be named
     * by, so it is `home`, the name Laravel's starter kits give `/` and the
     * one `guest` sends a signed-in visitor to - unless app/home is a page,
     * which has it by its path. A route.ts is not somewhere a visitor is
     * sent, so it is not named.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, string> pattern => name
     */
    public static function names(array $manifest): array
    {
        $out = [];
        $root = false;

        foreach ($manifest['routes'] ?? [] as $route) {
            if (! is_array($route) || ! is_array($route['segments'] ?? null)) {
                continue;
            }

            if ($route['segments'] === []) {
                $root = true;

                continue;
            }

            $values = array_map(fn ($segment) => (string) ($segment['value'] ?? ''), $route['segments']);

            if (in_array('', $values, true)) {
                continue;
            }

            $out[self::pattern($route['segments'])[0]] = implode('.', $values);
        }

        if ($root && ! in_array('home', $out, true)) {
            $out['/'] = 'home';
        }

        return $out;
    }

    /**
     * Where a page route is registered: a pattern under a folder the build
     * lists in `build.subdomains` (rscKit({ subdomains })) moves to that subdomain of the site, without the
     * directory. /merchant/onboarding is merchant.example.com and /onboarding;
     * /merchant is merchant.example.com and /.
     *
     * @param  list<string>  $subdomains
     * @return array{0: ?string, 1: string} domain, or null for the current host; uri
     */
    public static function placement(string $pattern, array $subdomains, ?string $site): array
    {
        $parts = explode('/', ltrim($pattern, '/'), 2);

        if ($site === null || $site === '' || ! in_array($parts[0], $subdomains, true)) {
            return [null, $pattern];
        }

        return [$parts[0].'.'.$site, '/'.($parts[1] ?? '')];
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
