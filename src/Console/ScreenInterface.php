<?php

declare(strict_types=1);

namespace OpenSim\Console;

/**
 * The console of an instance run in a screen session: it also has a screen to read and to attach to.
 */
interface ScreenInterface extends ConsoleInterface
{
    /** Whether the session exists. */
    public function exists(): bool;

    /** The scrollback of the session, as the screen shows it. */
    public function screenText(): string;

    /** Attach to the session: Ctrl-A D leaves it running. Returns the status of screen. */
    public function attach(): int;
}
