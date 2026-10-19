<?php

declare(strict_types=1);

namespace OpenSim\Network;

use OpenSim\Data\Cache;
use OpenSim\Hypergrid\Destination;
use OpenSim\Runtime\Environment;

/**
 * What the grids said of themselves at `/get_grid_info` when they were verified, kept. What a grid says is the truth about it,
 * whatever address it was reached at: a grid that changed its domain name says the new one when it is asked at the old one.
 *
 * Nothing is asked here unless it is a verification: `verify()` asks the grid and keeps its answer; everything else reads what was
 * kept (`saved()`, `login()`). The grids do not change every minute, what was kept is valid for a day, and a grid that is offline
 * for hours does not make it less valid: a failed verification leaves it as it is. The time is over once a day, then it is gone, and
 * whoever verifies next keeps the new answer. No other address is tried in place of the one of a grid.
 *
 * What is kept is in a Cache: in memory for the session, and in its store for the day (the files of the install, or the search
 * database of the grid when the caller gives `Cache::for($environment, $config)`). A grid that did not answer is not asked again
 * by `login()` for ten minutes.
 */
final class GridInfoCache
{
    /** Seconds what a grid said is valid, from the moment it was verified. */
    public const TTL = 86400;

    /** Seconds before a grid that did not answer is asked again by `login()`. */
    public const SILENT = 600;

    private Cache $cache;

    public function __construct(
        private readonly Environment $environment,
        private readonly int $ttl = self::TTL,
        ?Cache $cache = null,
    ) {
        $this->cache = $cache ?? Cache::for($environment);
    }

    /**
     * What the grid said when it was last verified, as long as it is valid. Nothing is asked.
     *
     * @return ?array<string,string> every element it said, the free ones included
     */
    public function saved(string $login): ?array
    {
        $kept = $this->cache->get('grid-info.' . $this->key($login));

        return is_array($kept) ? $kept : null;
    }

    /**
     * Ask the grid now and keep what it says, under the address it was asked at and under the login it says. A grid that does not
     * answer is a failure: what was kept of it stays as it is.
     *
     * @return ?array<string,string> what it said, null when it did not answer
     */
    public function verify(string $login): ?array
    {
        $asked = Destination::loginUrl($login);
        $said = $asked === null ? [] : GridInfoReader::read($asked, $this->environment->http);
        if ($said === []) {
            $asked === null || $this->cache->set('grid-info-silent.' . $this->key($asked), true, self::SILENT);

            return null;
        }
        $this->cache->forget('grid-info-silent.' . $this->key($asked));
        $this->save($asked, $said);
        if (($official = Destination::loginUrl($said['login'] ?? '')) !== null) {
            $this->save($official, $said);
        }

        return $said;
    }

    /** @param array<string,string> $said */
    public function save(string $login, array $said): void
    {
        $this->cache->set('grid-info.' . $this->key($login), $said, $this->ttl);
    }

    /**
     * The login address of the grid an address leads to, as the grid said it when it was verified: `http://grid.example.org:8002`
     * for an address that is the old one of that grid. A grid that was verified and says no login is at the address it was asked at.
     * Null when nothing was kept of it (unless `$verify` asks the grid now, and it answers).
     */
    public function login(string $address, bool $verify = false): ?string
    {
        $asked = Destination::loginUrl($address);
        if ($asked === null) {
            return null;
        }
        $said =
            $this->saved($asked) ??
            ($verify && !$this->cache->has('grid-info-silent.' . $this->key($asked)) ? $this->verify($asked) : null);

        return $said === null ? null : Destination::loginUrl($said['login'] ?? '') ?? $asked;
    }

    private function key(string $login): string
    {
        return md5(strtolower(rtrim((string) Destination::loginUrl($login), '/')));
    }
}
