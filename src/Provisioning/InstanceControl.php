<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Exceptions\InstanceFailed;
use OpenSim\OpenSim;

/**
 * Starting and restarting instances from the setup, in the process that runs it (the one that writes, as the user of the
 * instances), the way `opensim start` does.
 */
final class InstanceControl
{
    /** @var ?\Closure(UiInterface,string,string,bool):array{0:int,1:string} how the application starts and restarts instances for the setup */
    private static ?\Closure $runner = null;

    /**
     * Start and restart through something else than this process: the command line of the application, which acts as the user
     * that runs the instances whoever calls it. The setup is not always that user, and an instance started by another one cannot
     * be stopped, nor seen in a screen, by the user that runs the others.
     *
     * @param ?\Closure(UiInterface,string,string,bool):array{0:int,1:string} $runner given the interface, the action, the target and `now`
     */
    public static function runThrough(?\Closure $runner): void
    {
        self::$runner = $runner;
    }

    /**
     * @param string $action start or restart
     * @param string $target a grid (its Robust) or a simulator, as the command line takes it
     * @param bool $now restart without warning the users
     * @return array{0:int,1:string} the status (0 when nothing failed) and what was said
     */
    public static function run(UiInterface $ui, string $action, string $target, bool $now = false): array
    {
        if (self::$runner !== null) {
            return (self::$runner)($ui, $action, $target, $now);
        }
        $reporter = new UiReporter($ui);
        $environment = OpenSim::environment($reporter);
        $results =
            $action === 'restart'
                ? OpenSim::restart($target, $now, $environment)
                : OpenSim::start($target, $environment);
        foreach ($results as $result) {
            if ($result instanceof InstanceFailed) {
                return [1, $reporter->said()];
            }
        }

        return [0, $reporter->said()];
    }
}
