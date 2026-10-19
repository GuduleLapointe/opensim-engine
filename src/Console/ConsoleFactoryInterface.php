<?php

declare(strict_types=1);

namespace OpenSim\Console;

use OpenSim\Controllers\AbstractController;

/**
 * Gives the console of an instance.
 */
interface ConsoleFactoryInterface
{
    public function for(AbstractController $instance): ConsoleInterface;

    /** The screen session of an instance, whether it has one now or not. */
    public function screen(string $name): ScreenInterface;

    /** Whether a screen session of this name runs (the whole name, not the start of one). */
    public function sessionExists(string $name): bool;

    /** Drop the dead screen sessions, so that they are not taken for a running instance. */
    public function wipeSessions(): void;
}
