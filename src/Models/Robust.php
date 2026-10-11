<?php

declare(strict_types=1);

namespace OpenSim\Models;

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

    public static function enabledFolder(): string
    {
        return 'robust.d';
    }

    protected function consolePortKeys(): array
    {
        return ['ConsolePort', 'consoleport'];
    }
}
