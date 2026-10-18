<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridFileService;

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
