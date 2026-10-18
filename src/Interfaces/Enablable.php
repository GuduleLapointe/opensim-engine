<?php

declare(strict_types=1);

namespace OpenSim\Interfaces;

/**
 * What can be enabled and disabled: a grid and its simulators (their config is linked where the launcher finds it), a region (its
 * file is the one the simulator loads), an avatar (its account is active). Enabling a running instance does not start it, disabling
 * does not stop it.
 */
interface Enablable
{
    public function isEnabled(): bool;

    /**
     * @return bool whether it is enabled now
     * @throws \OpenSim\Exceptions\ChannelUnavailable when nothing can do it
     */
    public function enable(): bool;

    /** @throws \OpenSim\Exceptions\ChannelUnavailable when nothing can do it */
    public function disable(): void;
}
