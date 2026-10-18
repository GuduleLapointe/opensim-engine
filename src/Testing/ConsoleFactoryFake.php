<?php

declare(strict_types=1);

namespace OpenSim\Testing;

use OpenSim\Console\ConsoleFactoryInterface;
use OpenSim\Console\ConsoleInterface;
use OpenSim\Console\ScreenInterface;
use OpenSim\Controllers\Controller;

/**
 * One fake console per instance.
 */
final class ConsoleFactoryFake implements ConsoleFactoryInterface
{
    /** @var array<string,ConsoleFake> */
    public array $consoles = [];

    /** @var array<string,true> the screen sessions that exist */
    public array $sessions = [];

    public function for(Controller $instance): ConsoleInterface
    {
        return $this->console($instance->name());
    }

    public function console(string $name): ConsoleFake
    {
        return $this->consoles[$name] ??= new ConsoleFake();
    }

    public function screen(string $name): ScreenInterface
    {
        return $this->console($name);
    }

    public function sessionExists(string $name): bool
    {
        return isset($this->sessions[$name]);
    }

    public function wipeSessions(): void
    {
    }
}
