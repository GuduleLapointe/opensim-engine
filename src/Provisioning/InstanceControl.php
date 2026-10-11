<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\OpenSim;

/**
 * Starting and restarting instances from the setup, in the process that runs it (the one that writes, as the user of the
 * instances), the way `opensim start` does.
 */
final class InstanceControl
{
    /**
     * @param string $action start or restart
     * @param string $target a grid (its Robust) or a simulator, as the command line takes it
     * @param bool $now restart without warning the users
     * @return array{0:int,1:string} the status (0 when nothing failed) and what was said
     */
    public static function run(UiInterface $ui, string $action, string $target, bool $now = false): array
    {
        $reporter = new UiReporter($ui);
        $environment = OpenSim::environment($reporter);
        $results = $action === 'restart' ? OpenSim::restart($target, $now, $environment) : OpenSim::start($target, $environment);
        foreach ($results as $result) {
            if (!$result->ok()) {
                return [1, $reporter->said()];
            }
        }

        return [0, $reporter->said()];
    }
}
