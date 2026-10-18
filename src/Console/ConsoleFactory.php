<?php

declare(strict_types=1);

namespace OpenSim\Console;

use OpenSim\Controllers\Controller;

/**
 * The remote console of an instance that has one (a port, a user and a password), else its screen session.
 */
final class ConsoleFactory implements ConsoleFactoryInterface
{
    public function for(Controller $instance): ConsoleInterface
    {
        $remote = $instance->remoteConsole();

        return $remote !== null
            ? new RestConsole($remote['port'], $remote['user'], $remote['password'])
            : new ScreenConsole($instance->name());
    }

    public function screen(string $name): ScreenInterface
    {
        return new ScreenConsole($name);
    }

    public function sessionExists(string $name): bool
    {
        return $this->screen($name)->exists();
    }

    public function wipeSessions(): void
    {
        ScreenConsole::wipe();
    }
}
