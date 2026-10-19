<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Data\OSPDO;
use OpenSim\Runtime\ClockInterface;

/**
 * The simulators registered to the search (the table `hostsregister`): the ones whose snapshot is read now and then. A simulator
 * registers when it starts and unregisters when it stops; one that does not answer is looked at less and less often, then forgotten.
 */
final class SearchRegistry
{
    /** Seconds between two looks at a host that answers, unless it says more. */
    public const INTERVAL = 600;

    /** Hosts read by one pass. */
    public const BATCH = 100;

    /**
     * @param string $regions the table of the regions of the search (see Region::searchTable())
     */
    public function __construct(
        private readonly OSPDO $db,
        private readonly string $regions,
        private readonly ?ClockInterface $clock = null,
    ) {}

    /** A simulator is there: it is read as soon as the next pass comes. */
    public function register(string $host, int $port): void
    {
        $known = $this->db->prepareAndExecute(
            'SELECT register FROM hostsregister WHERE host = :host AND port = :port',
            [
                'host' => $host,
                'port' => $port,
            ],
        );
        if ($known && $known->fetch() !== false) {
            $this->db->prepareAndExecute(
                'UPDATE hostsregister SET register = :now, nextcheck = 0, checked = 0, failcounter = 0 WHERE host = :host AND port = :port',
                ['now' => $this->now(), 'host' => $host, 'port' => $port],
            );

            return;
        }
        $this->db->insert('hostsregister', [
            'host' => $host,
            'port' => $port,
            'register' => $this->now(),
            'nextcheck' => 0,
            'checked' => 0,
            'failcounter' => 0,
            'gatekeeperURL' => '',
        ]);
    }

    /** A simulator is gone: it is forgotten, and so is what it told of its regions. */
    public function unregister(string $host, int $port): void
    {
        $this->db->prepareAndExecute('DELETE FROM hostsregister WHERE host = :host AND port = :port', [
            'host' => $host,
            'port' => $port,
        ]);
        $regions = $this->db->prepareAndExecute("SELECT regionUUID FROM {$this->regions} WHERE url = ?", [
            "http://$host:$port/",
        ]);
        foreach ($regions ? $regions->fetchAll(\PDO::FETCH_NUM) : [] as [$uuid]) {
            $this->forgetRegion((string) $uuid);
            $this->db->prepareAndExecute("DELETE FROM {$this->regions} WHERE regionUUID = ?", [$uuid]);
        }
    }

    /** Forget what the search knows of a region: its parcels, the land for sale, the popular places, the objects. */
    public function forgetRegion(string $uuid): void
    {
        $this->db->prepareAndExecute(
            'DELETE FROM popularplaces WHERE parcelUUID IN (SELECT parcelUUID FROM parcels WHERE regionUUID = ?)',
            [$uuid],
        );
        foreach (['parcels', 'allparcels', 'parcelsales'] as $table) {
            $this->db->prepareAndExecute("DELETE FROM $table WHERE regionUUID = ?", [$uuid]);
        }
        $this->db->prepareAndExecute('DELETE FROM objects WHERE regionuuid = ?', [$uuid]);
    }

    /**
     * The hosts to read now. When every host was read, they are all due again.
     *
     * @return list<array{host:string,port:int}>
     */
    public function due(): array
    {
        $sql = 'SELECT host, port FROM hostsregister WHERE nextcheck < :now AND checked = 0 LIMIT ' . self::BATCH;
        $hosts = $this->rows($sql);
        if ($hosts === []) {
            $this->db->prepareAndExecute('UPDATE hostsregister SET checked = 0');
            $hosts = $this->rows($sql);
        }

        return $hosts;
    }

    /** Whether a host is waiting to be read. */
    public function waiting(): bool
    {
        return (int) $this->db->get_var('SELECT COUNT(*) FROM hostsregister WHERE nextcheck < :now', [
            'now' => $this->now(),
        ]) > 0;
    }

    /** Seconds since the host failed `$fails` times in a row: the interval doubles each time. */
    public static function backoff(int $fails): int
    {
        return self::INTERVAL * 2 ** $fails;
    }

    /** How many times a host failed in a row. */
    public function failures(string $host, int $port): int
    {
        return (int) $this->db->get_var('SELECT failcounter FROM hostsregister WHERE host = :host AND port = :port', [
            'host' => $host,
            'port' => $port,
        ]);
    }

    /** Set when a host is read next, and how many times it failed. */
    public function schedule(string $host, int $port, int $next, int $failures = 0): void
    {
        $this->db->prepareAndExecute(
            'UPDATE hostsregister SET failcounter = :failures, nextcheck = :next, checked = 1 WHERE host = :host AND port = :port',
            ['failures' => $failures, 'next' => $next, 'host' => $host, 'port' => $port],
        );
    }

    /**
     * A simulator registers while it starts, its regions are not all loaded yet: it is read again soon, when it could be read (one
     * that cannot be reached keeps its backoff). Not within the minute: a simulator answers one request a minute to the same address.
     */
    public function again(string $host, int $port, int $after = 90): void
    {
        $this->db->prepareAndExecute(
            'UPDATE hostsregister SET nextcheck = :next, checked = 0 WHERE host = :host AND port = :port AND failcounter = 0',
            ['next' => $this->now() + $after, 'host' => $host, 'port' => $port],
        );
    }

    public function now(): int
    {
        return (int) ($this->clock?->time() ?? time());
    }

    /** @return list<array{host:string,port:int}> */
    private function rows(string $sql): array
    {
        $found = $this->db->prepareAndExecute($sql, ['now' => $this->now()]);
        $hosts = [];
        foreach ($found ? $found->fetchAll(\PDO::FETCH_NUM) : [] as [$host, $port]) {
            $hosts[] = ['host' => (string) $host, 'port' => (int) $port];
        }

        return $hosts;
    }
}
