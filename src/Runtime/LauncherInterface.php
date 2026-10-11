<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * Starts an instance as a process of its own that goes on when the caller ends.
 */
interface LauncherInterface
{
    /** Start it and return at once. False when it could not even be started. */
    public function launch(LaunchSpec $spec): bool;
}
