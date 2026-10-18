<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * How to start an instance: what the launcher needs to know, nothing of OpenSimulator.
 */
final class LaunchSpec
{
    /**
     * @param string $name the instance (the name of its screen session)
     * @param string $directory where the process runs (the bin folder of the core)
     * @param list<string> $command the program and its arguments
     * @param array<string,string> $environment variables added to the environment of the process
     * @param string $output the file its first output goes to (what it prints before its own log has anything)
     * @param bool $screen run it in a screen session (an instance without a remote console)
     * @param bool $bigStack a region runs with a big stack, as the opensim.sh of the distribution does
     */
    public function __construct(
        public readonly string $name,
        public readonly string $directory,
        public readonly array $command,
        public readonly array $environment = [],
        public readonly string $output = '',
        public readonly bool $screen = false,
        public readonly bool $bigStack = false,
    ) {}
}
