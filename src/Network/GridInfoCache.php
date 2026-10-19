<?php

declare(strict_types=1);

namespace OpenSim\Network;

use OpenSim\Hypergrid\Destination;
use OpenSim\Runtime\Environment;
use OpenSim\Support\FileCache;

/**
 * What the grids say of themselves at `/get_grid_info`, kept for a while: asked at every call, a viewer turning its head would ask a
 * grid dozens of times. An answer is kept for `$ttl` seconds, an address that does not answer is kept as a failure for a shorter time
 * (a grid that is down is not asked again at once). Nothing older than that is served: when the time is over the grid is asked
 * again, and if it does not answer it is a failure, no other address is tried and no old answer is given.
 *
 * What a grid says is the truth about it, whatever the address it was reached at: `login()` gives its login address.
 */
final class GridInfoCache
{
    /** Seconds an answer is kept. */
    public const TTL = 300;

    /** Seconds a failure is kept. */
    public const FAILED_TTL = 30;

    private FileCache $files;

    public function __construct(
        private readonly Environment $environment,
        private readonly int $ttl = self::TTL,
        private readonly int $failedTtl = self::FAILED_TTL,
    ) {
        $root = $environment->layout->cacheRoot() ?: sys_get_temp_dir() . '/opensim-cache';
        $this->files = new FileCache($root . '/grid-info', 86400, $environment->clock);
    }

    /**
     * What the grid says of itself at its login address, null when it does not answer.
     *
     * @param bool $fresh ask the grid whatever is kept (when the information is saved)
     * @return ?array<string,string> every element it says, the free ones included
     */
    public function read(string $login, bool $fresh = false): ?array
    {
        $key = md5(strtolower(rtrim($login, '/')));
        $now = (int) $this->environment->clock->time();
        if (!$fresh && ($kept = $this->files->get($key)) !== null) {
            $age = $now - (int) ($kept['meta']['time'] ?? 0);
            $ok = (bool) ($kept['meta']['ok'] ?? false);
            if ($age >= 0 && $age < ($ok ? $this->ttl : $this->failedTtl)) {
                return $ok ? (array) json_decode($kept['data'], true) : null;
            }
        }
        $said = GridInfoReader::read($login, $this->environment->http);
        $this->files->put($key, json_encode($said, JSON_UNESCAPED_SLASHES) ?: '[]', [
            'ok' => $said !== [],
            'time' => $now,
        ]);

        return $said !== [] ? $said : null;
    }

    /**
     * The login address of the grid an address leads to, as the grid says it: `http://speculoos.world:8002` for a grid asked at an old
     * domain name that now says that one. Null when the grid does not answer: it is a failure. A grid that answers without saying
     * its login is at the address it was asked at.
     */
    public function login(string $address, bool $fresh = false): ?string
    {
        $asked = Destination::loginUrl($address);
        if ($asked === null) {
            return null;
        }
        $said = $this->read($asked, $fresh);

        return $said === null ? null : Destination::loginUrl($said['login'] ?? '') ?? $asked;
    }
}
