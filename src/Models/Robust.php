<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\ConnectionString;
use OpenSim\Config\GridFileService;
use OpenSim\Data\Collection;
use OpenSim\Data\Database;
use OpenSim\Runtime\Environment;

/**
 * The Robust of a grid: the server of its services (login, users, inventory, assets, presence). A grid has one of its own on
 * this machine, or none when it is run elsewhere.
 */
final class Robust extends Instance
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

    /** The Robust of every grid of the machine that has one. See Grid::all() for the options. */
    public static function all(Environment $environment, array $options = []): Collection
    {
        $robusts = [];
        foreach (Grid::all($environment)->items() as $grid) {
            $robust = $grid->robust();
            $robust !== null && $robusts[] = $robust;
        }
        $yesNo = static fn(\Closure $is): array => [static fn(self $robust, string $value): bool => $is($robust) === ($value === 'yes'), ['yes', 'no']];

        return self::listing(
            $robusts,
            $options,
            static fn(self $robust): string => "{$robust->slug()} {$robust->gridName()} {$robust->uri()}",
            ['name' => static fn(self $robust): string => $robust->slug(), 'port' => static fn(self $robust): int => (int) $robust->publicPort()],
            [
                'enabled' => $yesNo(static fn(self $robust): bool => $robust->isEnabled()),
                'running' => $yesNo(static fn(self $robust): bool => $robust->isRunning()),
            ],
            [
                ['name' => 'name', 'label' => _('Name'), 'sortable' => true],
                ['name' => 'uri', 'label' => _('Address')],
                ['name' => 'port', 'label' => _('Port'), 'sortable' => true],
                ['name' => 'enabled', 'label' => _('Enabled')],
                ['name' => 'running', 'label' => _('Running')],
            ],
        );
    }

    /** The Robust of a grid, by its nick or its address. */
    public static function find(Environment $environment, string $id): ?static
    {
        foreach (self::all($environment)->items() as $robust) {
            if (strcasecmp($robust->slug(), $id) === 0 || strcasecmp((string) $robust->uri(), $id) === 0) {
                return $robust;
            }
        }

        return null;
    }

    /** Where the viewers and the other grids reach it: `host:port` of the grid. */
    public function uri(): ?string
    {
        $host = $this->baseHostname();
        $port = $this->publicPort();

        return $host !== null && $host !== '' && $port !== null ? "$host:$port" : null;
    }

    public function detail(): array
    {
        return [
            'id' => $this->id(),
            'uri' => $this->uri(),
            'name' => $this->name(),
            'slug' => $this->slug(),
            'grid' => $this->grid()?->nick(),
            'gridName' => $this->gridName(),
            'ini' => $this->ini(),
            'link' => $this->link(),
            'enabled' => $this->isEnabled(),
            'running' => $this->isRunning(),
            'baseHostname' => $this->baseHostname(),
            'publicPort' => $this->publicPort(),
            'privatePort' => $this->privatePort(),
            'webUrl' => $this->webUrl(),
            'consolePort' => $this->consolePort(),
            'console' => $this->remoteConsole() !== null ? 'rest' : 'screen',
            'databaseName' => $this->databaseName(),
            'connectors' => $this->connectors(),
            'regionFlags' => $this->regionFlags(),
            'core' => $this->core()?->directory(),
        ];
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
