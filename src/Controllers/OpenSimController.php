<?php

declare(strict_types=1);

namespace OpenSim\Controllers;

use OpenSim\Config\ConnectionString;
use OpenSim\Config\GridFileService;
use OpenSim\Data\Database;
use OpenSim\Provisioning\SimulatorMaster;

/**
 * What runs a simulator: OpenSim, launched with dotnet from the config of the simulator (`<name>.ini`), which runs one or
 * several regions of a grid. It starts, stops and is talked to; what the config says of it (its port, its database, its estate) is
 * asked of it, and the simulator, the model, composes it (see Simulator).
 */
final class OpenSimController extends AbstractController
{
    public function type(): string
    {
        return 'opensim';
    }

    public function assembly(): string
    {
        return 'OpenSim';
    }

    public static function enabledFolder(): string
    {
        return 'opensim.d';
    }

    protected function consolePortKeys(): array
    {
        return ['console_port', 'consoleport'];
    }

    protected function listeningPorts(): array
    {
        $uses = [];
        self::addPort($uses, $this->httpPort(), 'tcp', 'HTTP of the simulator: viewers, grid, neighbours', 'public');
        self::addPort($uses, $this->settingAnyCase('Network', 'http_listener_sslport'), 'tcp', 'HTTPS of the simulator', 'public');
        self::addPort($uses, $this->settingAnyCase('XMLRPC', 'XmlRpcPort'), 'tcp', 'XML-RPC of the simulator (scripts)', 'public');
        // The UDP port of each region, the one the viewers send to
        $directory = $this->regionsDirectory();
        foreach ($directory !== '' ? (glob("$directory/*.ini") ?: []) : [] as $file) {
            $text = str_replace("\r\n", "\n", GridFileService::read($file));
            if (preg_match_all('/^\s*\[([^\]]+)\]\s*$(.*?)(?=^\s*\[|\z)/ms', $text, $blocks, PREG_SET_ORDER) > 0) {
                foreach ($blocks as $block) {
                    if (preg_match('/^\s*InternalPort\s*=\s*"?(\d+)/mi', $block[2], $m) === 1) {
                        self::addPort($uses, $m[1], 'udp', 'region ' . trim($block[1]) . ': viewers', 'public');
                    }
                }
            }
        }

        return $uses;
    }

    protected function configFiles(): array
    {
        $grid = $this->gridDirectory();

        return $grid !== null && SimulatorMaster::exists($grid)
            ? [SimulatorMaster::path($grid), $this->ini()]
            : [$this->ini()];
    }

    /**
     * The master of its grid, made again when the core or the grid changed, null when the grid has none (its simulators have
     * their whole config).
     */
    public function master(string $binDir): ?string
    {
        $grid = $this->gridDirectory();
        if ($grid === null || !SimulatorMaster::exists($grid)) {
            return null;
        }
        (new SimulatorMaster())->ensure($grid, $binDir);
        $this->forget();

        return SimulatorMaster::path($grid);
    }

    /** The database of its regions: [SimulationDataStore], else [DatabaseService]. */
    public function database(): ?ConnectionString
    {
        return $this->connection(Database::Simulator);
    }

    /** The database of its estates: [EstateDataStore], else EstateConnectionString of [DatabaseService], else the database of its regions. */
    public function estateDatabase(): ?ConnectionString
    {
        return $this->connection(Database::Estate);
    }

    /** The port it listens on, null when its config does not say it. */
    public function httpPort(): ?int
    {
        return $this->own('httpPort');
    }

    public function estateName(): ?string
    {
        return $this->own('estateName');
    }

    public function estateOwner(): ?string
    {
        return $this->own('estateOwner');
    }

    /** The folder of the descriptions of its regions, empty when its config does not say where they are. */
    public function regionsDirectory(): string
    {
        return rtrim($this->launchSetting('regionload_regionsdir'), '/');
    }

    /** Whether it has a region to load: a region file in its regions folder. One without waits for the answers of a region to create. */
    public function hasRegion(): bool
    {
        $dir = $this->regionsDirectory();

        // A simulator that does not say where its regions are (one made by hand) is left alone
        return $dir === '' || glob("$dir/*.ini") !== [];
    }
}
