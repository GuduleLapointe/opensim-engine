<?php

declare(strict_types=1);

namespace OpenSim\Network;

use OpenSim\Hypergrid\Destination;
use OpenSim\Runtime\Environment;
use OpenSim\Support\FileCache;

/**
 * What the grids said of themselves at `/get_grid_info` when they were verified, kept. What a grid says is the truth about it,
 * whatever address it was reached at: a grid that changed its domain name says the new one when it is asked at the old one.
 *
 * Nothing is asked here unless it is a verification: `verify()` asks the grid and keeps its answer; everything else reads what was
 * kept (`saved()`, `login()`). The grids do not change every minute, what was kept is valid for a day, and a grid that is offline
 * for hours does not make it less valid: a failed verification leaves it as it is. The time is over once a day, then it is gone, and
 * whoever verifies next keeps the new answer. No other address is tried in place of the one of a grid.
 */
final class GridInfoCache
{
    /** Seconds what a grid said is valid, from the moment it was verified. */
    public const TTL = 86400;

    private FileCache $files;

    public function __construct(private readonly Environment $environment, int $ttl = self::TTL)
    {
        $root = $environment->layout->cacheRoot() ?: sys_get_temp_dir() . '/opensim-cache';
        $this->files = new FileCache($root . '/grid-info', $ttl, $environment->clock);
    }

    /**
     * What the grid said when it was last verified, as long as it is valid. Nothing is asked.
     *
     * @return ?array<string,string> every element it said, the free ones included
     */
    public function saved(string $login): ?array
    {
        $kept = $this->files->get($this->key($login));

        return $kept === null ? null : (array) json_decode($kept['data'], true);
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
            return null;
        }
        $this->save($asked, $said);
        if (($official = Destination::loginUrl($said['login'] ?? '')) !== null) {
            $this->save($official, $said);
        }

        return $said;
    }

    /** @param array<string,string> $said */
    public function save(string $login, array $said): void
    {
        $this->files->put($this->key($login), json_encode($said, JSON_UNESCAPED_SLASHES) ?: '[]');
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
        $said = $this->saved($asked) ?? ($verify ? $this->verify($asked) : null);

        return $said === null ? null : Destination::loginUrl($said['login'] ?? '') ?? $asked;
    }

    private function key(string $login): string
    {
        return md5(strtolower(rtrim((string) Destination::loginUrl($login), '/')));
    }
}
