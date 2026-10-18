<?php

declare(strict_types=1);

namespace OpenSim\Controllers;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\ConnectionString;
use OpenSim\Config\GridFileService;
use OpenSim\Data\Database;

/**
 * The Robust of a grid: the server of its services (login, users, inventory, assets, presence). A grid has one of its own on
 * this machine, or none when it is run elsewhere.
 */
final class RobustController extends Controller
{
    public function type(): string
    {
        return 'robust';
    }

    public function assembly(): string
    {
        return 'Robust';
    }

    protected function linkName(): string
    {
        return $this->grid()?->nick() ?? parent::linkName();
    }

    /** Where the viewers and the other grids reach it: `host:port` of the grid. */
    public function uri(): ?string
    {
        $host = $this->baseHostname();
        $port = $this->publicPort();

        return $host !== null && $host !== '' && $port !== null ? "$host:$port" : null;
    }

    /** The database of its services that have none of their own: [DatabaseService]. */
    public function database(): ?ConnectionString
    {
        return $this->connection(Database::Robust);
    }

    /** The name the grid gives itself (`gridname` of [GridInfoService]). */
    public function gridName(): ?string
    {
        return $this->own('gridName');
    }

    public function baseHostname(): ?string
    {
        return $this->own('baseHostname');
    }

    /** The web site of the grid (`WebURL`), where its helpers are. */
    public function webUrl(): ?string
    {
        return $this->own('webUrl');
    }

    /** The port the viewers and the other grids reach it by. */
    public function publicPort(): ?int
    {
        return $this->own('publicPort');
    }

    /** The port of the simulators of the grid, which they reach it by. */
    public function privatePort(): ?int
    {
        return $this->own('privatePort');
    }

    /**
     * The services it serves: the connectors of its [ServiceList].
     *
     * @return list<string>
     */
    public function connectors(): array
    {
        return (array) ($this->own('connectors') ?? []);
    }

    public function serves(string $connector): bool
    {
        return in_array($connector, $this->connectors(), true);
    }

    /**
     * The roles its config gives to regions (Region_<Name> = "DefaultRegion, ...").
     *
     * @return list<string>
     */
    public function regionFlags(): array
    {
        return (array) ($this->own('regionFlags') ?? []);
    }

    /**
     * The connectors a Robust config serves: the keys of its [ServiceList].
     *
     * @return list<string>
     */
    public static function connectorsIn(string $robustIni): array
    {
        return array_keys(ConfigReader::parse(GridFileService::read($robustIni))['ServiceList'] ?? []);
    }

    public static function enabledFolder(): string
    {
        return 'robust.d';
    }

    protected function consolePortKeys(): array
    {
        return ['ConsolePort', 'consoleport'];
    }
}
