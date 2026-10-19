<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Data\OSPDO;
use OpenSim\Hypergrid\Destination;

/**
 * The guide of a grid that has no list of its own: what the search already knows, the popular places and the places shown in
 * search, ten of each. The guide shows them as two sections; nothing is asked of the operator.
 */
final class DefaultDestinations
{
    /** Places in each section. */
    public const LIMIT = 10;

    /**
     * @param string $regions the table of the regions of the search
     * @param string $base where the places of the grid are reached from (`host:port`), for the rows that do not say their grid
     * @param ?string $gatekeeper the login address of the grid: only its places are listed (a search can be shared by several grids)
     */
    public function __construct(
        private readonly OSPDO $search,
        private readonly string $regions,
        private readonly string $base,
        private readonly ?string $gatekeeper = null,
    ) {}

    /**
     * @return array<string,list<array{name:string,url:string}>> by section, empty when the search knows nothing
     */
    public function lists(): array
    {
        if (!$this->search->tables_exist(['popularplaces', 'parcels', $this->regions])) {
            return [];
        }
        $popular = $this->rows(
            'SELECT pop.name AS name, r.regionname AS region, par.landingpoint AS landing, pop.gatekeeperURL AS gatekeeper, par.parcelUUID AS parcel FROM popularplaces AS pop ' .
                "INNER JOIN parcels AS par ON pop.parcelUUID = par.parcelUUID INNER JOIN {$this->regions} AS r ON par.regionUUID = r.regionUUID %s ORDER BY pop.dwell DESC, par.parcelname",
            'pop.gatekeeperURL',
            self::LIMIT,
        );
        // The places that are popular are in the first section: the second lists the others
        $known = array_column($popular, 'parcel');
        $shown = array_slice(
            array_filter(
                $this->rows(
                    "SELECT par.parcelname AS name, r.regionname AS region, par.landingpoint AS landing, par.gatekeeperURL AS gatekeeper, par.parcelUUID AS parcel FROM parcels AS par INNER JOIN {$this->regions} AS r ON par.regionUUID = r.regionUUID %s ORDER BY par.parcelname",
                    'par.gatekeeperURL',
                    self::LIMIT * 2,
                ),
                static fn(array $row): bool => !in_array($row['parcel'], $known, true),
            ),
            0,
            self::LIMIT,
        );

        return array_filter([
            _('Popular places') => array_map($this->destination(...), $popular),
            _('Places in the search') => array_map($this->destination(...), array_values($shown)),
        ]);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $sql, string $column, int $limit): array
    {
        $values = [];
        $where = '';
        if ($this->gatekeeper !== null && $this->gatekeeper !== '') {
            // The tables keep the address with or without its last slash
            $where = "WHERE $column IN (:one, :two)";
            $values = ['one' => rtrim($this->gatekeeper, '/'), 'two' => rtrim($this->gatekeeper, '/') . '/'];
        }
        $found = $this->search->prepareAndExecute(sprintf($sql, $where) . " LIMIT $limit", $values ?: null);

        return $found ? $found->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /**
     * @param array<string,mixed> $row
     * @return array{name:string,url:string}
     */
    private function destination(array $row): array
    {
        $login = Destination::loginParts((string) ($row['gatekeeper'] ?? ''));
        $base = $login === null ? $this->base : $login['host'] . ($login['port'] !== null ? ':' . $login['port'] : '');
        $position = trim(str_replace(',', '/', (string) ($row['landing'] ?? '')), '/');

        return [
            'name' => (string) $row['name'],
            'url' => $base . '/' . $row['region'] . ($position !== '' ? '/' . $position : ''),
        ];
    }
}
