<?php

namespace RscKit;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Versions, in the app's cache.
 *
 * A page or a section names the data it shows - `section('repos', Repos, {
 * refreshOn: ({ params }) => [`team:${params.team}:repos`] })` - and this side
 * says when that changed, from a webhook, a job, a listener, anywhere:
 *
 *     Rsc::changed("team:$teamId:repos");
 *
 * Every open tab showing it refreshes. A name has a version, a number that
 * moves when it is said to have changed; nothing else travels. The renderer
 * reads versions at render and asks `__rsc.changed` for the ones that moved
 * since, and this answers at once - PHP-FPM holds no call open - so the
 * renderer asks again on its interval, a couple of seconds apart.
 *
 * The cache is what every server of the app already shares, which is why
 * the versions live there: a webhook landing on one server moves a version
 * every other server reads. A cache that is per server (file, array) is one
 * server's; `rsc.versions_store` names another store when the default is.
 */
class Versions
{
    /** The reserved name the renderer asks versions on. Answered here, never registered by an app. */
    public const FUNCTION = '__rsc.changed';

    public const PREFIX = 'rsc:version:';

    public function __construct(private readonly ?string $store = null) {}

    private function cache(): Repository
    {
        return Cache::store($this->store);
    }

    /** Say these names changed. */
    public function changed(string ...$names): void
    {
        foreach (array_unique($names) as $name) {
            $key = self::PREFIX.$name;

            // The first change makes the key, forever; the rest count.
            if (! $this->cache()->add($key, 1)) {
                $this->cache()->increment($key);
            }
        }
    }

    /**
     * The versions among `since` that differ now. A name never changed is 0.
     *
     * @param  array<string, int>  $since
     * @return array<string, int>
     */
    public function versions(array $since): array
    {
        if ($since === []) {
            return [];
        }

        $names = array_keys($since);
        $keys = array_map(fn (string $name) => self::PREFIX.$name, $names);
        $stored = $this->cache()->many($keys);
        $differ = [];

        foreach ($names as $i => $name) {
            $current = (int) ($stored[$keys[$i]] ?? 0);

            if ($current !== (int) $since[$name]) {
                $differ[$name] = $current;
            }
        }

        return $differ;
    }

    /**
     * The host function: `{ since, wait }` to `{ versions }`.
     *
     * `wait` is not honoured - a worker held open is a worker the next
     * request waits for - and the protocol allows that: the renderer asks
     * again on its interval.
     *
     * @param  array<string, mixed>  $query
     * @return array{versions: object}
     */
    public function answer(array $query = []): array
    {
        $since = is_array($query['since'] ?? null) ? $query['since'] : [];

        // An object even when empty: json_encode writes an empty array as [].
        return ['versions' => (object) $this->versions($since)];
    }
}
