<?php

declare(strict_types=1);

namespace OpenSim\Data;

use OpenSim\Config\GridConfig;
use OpenSim\Runtime\ClockInterface;
use OpenSim\Runtime\Environment;

/**
 * What takes a search to know (an answer of a grid, a row of a database, a file) is searched once for the session and kept in memory,
 * and once for a while when it is worth it, in a store the requests share.
 *
 *     $cache = Cache::for($environment, $config);
 *     $cache->set('region.' . $uuid, $row);             // for this session only
 *     $cache->set('region.' . $uuid, $row, 86400);      // for a day, for every request
 *     $row = $cache->get('region.' . $uuid);
 *     $row = $cache->remember('region.' . $uuid, fn() => Region::find($config, $uuid)?->detail(), 3600);
 *
 * The time to live tells which: none keeps it in memory, a number of seconds keeps it in the store as well (0 for ever). The store is
 * the `oshelpers_cache` table of the search database of the grid when it has one (the table the helpers and the aggregator use),
 * else files in the cache folder of the install. A memory that holds `null` holds it: a search that found nothing is not made again.
 */
final class Cache
{
    /** @var array<string,array<string,array{value:mixed,expires:int}>> by scope, then by key */
    private static array $memory = [];

    public function __construct(
        private readonly ?CacheStoreInterface $store = null,
        private readonly ?ClockInterface $clock = null,
    ) {}

    /** The cache of the grid of a config: in its search database, else in the files of the install. */
    public static function for(Environment $environment, ?GridConfig $config = null): self
    {
        $pdo = $config === null ? null : Connections::pdo($config, Database::Search);
        $store =
            $pdo !== null
                ? new DatabaseCacheStore($pdo)
                : new FileCacheStore(($environment->layout->cacheRoot() ?: sys_get_temp_dir() . '/opensim-cache') . '/cache');

        return new self($store, $environment->clock);
    }

    /** Forget everything held in memory, by every cache. */
    public static function flush(): void
    {
        self::$memory = [];
    }

    /** Whether the cache holds it, valid. */
    public function has(string $key): bool
    {
        return $this->find($this->key($key)) !== null;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->find($this->key($key))['value'] ?? $default;
    }

    /**
     * @param int|false $ttl false keeps it for this session only, else for this number of seconds in the store too (0 for ever)
     */
    public function set(string $key, mixed $value, int|false $ttl = false): void
    {
        $key = $this->key($key);
        $expires = $ttl === false || $ttl === 0 ? 0 : $this->now() + $ttl;
        self::$memory[$this->scope()][$key] = ['value' => $value, 'expires' => $expires];
        if ($ttl !== false && $value !== null) {
            $this->store?->put($key, $value, $expires);
        }
    }

    public function forget(string $key): void
    {
        $key = $this->key($key);
        unset(self::$memory[$this->scope()][$key]);
        $this->store?->forget($key);
    }

    /**
     * What the cache holds, else what the search gives, kept. A search that gives `null` is kept for this session only.
     *
     * @param \Closure():mixed $search
     * @param int|false $ttl see set()
     */
    public function remember(string $key, \Closure $search, int|false $ttl = false): mixed
    {
        if (($found = $this->find($this->key($key))) !== null) {
            return $found['value'];
        }
        $value = $search();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /** @return ?array{value:mixed,expires:int} */
    private function find(string $key): ?array
    {
        $scope = $this->scope();
        $held = self::$memory[$scope][$key] ?? null;
        if ($held !== null && ($held['expires'] === 0 || $held['expires'] > $this->now())) {
            return $held;
        }
        unset(self::$memory[$scope][$key]);
        $stored = $this->store?->get($key, $this->now());
        // What the store gives is kept in memory for the rest of the session
        $stored !== null && (self::$memory[$scope][$key] = $stored);

        return $stored;
    }

    /** Keys are kept readable in the table and fit its column. */
    private function key(string $key): string
    {
        return substr(preg_replace('/[^a-zA-Z0-9\-._]/', '_', $key) ?? '', 0, 200);
    }

    private function scope(): string
    {
        return $this->store?->scope() ?? 'memory';
    }

    private function now(): int
    {
        return (int) ($this->clock?->time() ?? time());
    }
}
