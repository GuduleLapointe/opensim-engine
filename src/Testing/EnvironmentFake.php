<?php

declare(strict_types=1);

namespace OpenSim\Testing;

use OpenSim\Config\Layout;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\RuntimeProbe;

/**
 * An environment that touches nothing of the machine it runs on: the settings of its layout are given, the processes, the
 * launcher, the consoles and the clock are fakes, nothing listens and nothing is installed.
 */
final class EnvironmentFake
{
    /**
     * @param array<string,string> $layout the settings of the install (EtcRoot, DataRoot, CoreDirectory...)
     * @param ?ProcessTableFake $processes the processes of the machine, none by default
     */
    public static function with(array $layout = [], ?ProcessTableFake $processes = null): Environment
    {
        $processes ??= new ProcessTableFake();

        return new Environment(
            new Layout($layout),
            $processes,
            new LauncherFake($processes),
            new ConsoleFactoryFake(),
            new ClockFake(),
            new ReporterFake(),
            null,
            new RuntimeProbe(static fn(string $command): string => ''),
            static fn(string $url): ?string => null,
        );
    }
}
