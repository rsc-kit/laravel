<?php

namespace RscKit;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    /** The channel a Postgres NOTIFY goes out on, which the renderer's postgresVersions listens to. */
    public const CHANNEL = 'rsc_versions';

    /**
     * @param  'cache'|'database'  $driver  Where versions are kept: the cache by default; the
     *                                      `rsc_versions` table, which the renderer can read itself
     */
    public function __construct(
        private readonly ?string $store = null,
        private readonly string $driver = 'cache',
        private readonly string $table = 'rsc_versions',
        private readonly ?string $connection = null,
        private readonly int $keepDays = 30,
    ) {}

    /**
     * The version a name moves to: the larger of one past where it was and
     * the current time in milliseconds.
     *
     * A counter repeats once its row or key is gone: a name starts again from
     * 0 and climbs back to a value a hidden tab still holds, and that tab
     * misses the change. A time never comes round again, so old names can be
     * deleted - or left to expire - at any moment, and a tab still holding
     * one refreshes once. It is also when the name last changed, which is
     * all cleanup needs. The same rule as rsc-kit's nextVersion and Go's
     * NextVersion.
     */
    public static function next(int $current = 0): int
    {
        return max($current + 1, (int) floor(microtime(true) * 1000));
    }

    private function db(): Connection
    {
        return DB::connection($this->connection);
    }

    private function cache(): Repository
    {
        return Cache::store($this->store);
    }

    /** Say these names changed. */
    public function changed(string ...$names): void
    {
        $names = array_values(array_unique($names));

        if ($names === []) {
            return;
        }

        if ($this->driver === 'database') {
            $this->changedInTable($names);

            return;
        }

        foreach ($names as $name) {
            $key = self::PREFIX.$name;

            // Read, then written with an expiry, so a name nobody changes
            // leaves the cache on its own. Not atomic: two changes at once
            // may write one version between them, which is still a change -
            // watchers only compare.
            $this->cache()->put(
                $key,
                self::next((int) $this->cache()->get($key, 0)),
                now()->addDays($this->keepDays),
            );
        }
    }

    /**
     * One upsert per name - the cross-process contract every store shares -
     * and on Postgres a NOTIFY, so a renderer listening hears it at once.
     *
     * @param  list<string>  $names
     */
    private function changedInTable(array $names): void
    {
        $db = $this->db();
        $version = $db->getQueryGrammar()->wrap($this->table.'.version');
        $now = self::next();

        // next(), in SQL every dialect has: CASE rather than GREATEST, which
        // SQLite spells MAX. $now is an integer this process made.
        $db->table($this->table)->upsert(
            array_map(fn (string $name) => ['name' => $name, 'version' => $now], $names),
            ['name'],
            ['version' => $db->raw("CASE WHEN {$version} + 1 > {$now} THEN {$version} + 1 ELSE {$now} END")],
        );

        if ($db->getDriverName() === 'pgsql') {
            $db->statement('NOTIFY '.self::CHANNEL);
        }
    }

    /**
     * Delete every name not changed in $days (the configured keep days by
     * default), and answer how many. The table only: cache keys expire on
     * their own. Always safe - a tab still holding a pruned name sees it
     * differ and refreshes once.
     */
    public function prune(?int $days = null): int
    {
        if ($this->driver !== 'database') {
            return 0;
        }

        $cutoff = (int) floor((microtime(true) - ($days ?? $this->keepDays) * 86400) * 1000);

        return $this->db()->table($this->table)->where('version', '<', $cutoff)->delete();
    }

    /** Whether versions are in the table, which pruning is for. */
    public function inTable(): bool
    {
        return $this->driver === 'database';
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

        $names = array_map('strval', array_keys($since));

        if ($this->driver === 'database') {
            $stored = $this->db()->table($this->table)->whereIn('name', $names)->pluck('version', 'name')->all();
            $keys = $names;
        } else {
            $keys = array_map(fn (string $name) => self::PREFIX.$name, $names);
            $stored = $this->cache()->many($keys);
        }

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
