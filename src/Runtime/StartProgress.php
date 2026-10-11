<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Models\Instance;
use OpenSim\Models\Simulator;

/**
 * How a simulator loads, shown while its start is waited for: what the process prints first, then the steps its log reports
 * (see StartupProgress), each one once.
 */
final class StartProgress
{
    /** @var array<string,true> */
    private array $shown = [];
    private readonly int $regions;

    /**
     * @param int $mark where the log of this start begins (see LogFile::mark())
     * @param string $output the file the process writes its first output to
     */
    public function __construct(
        private readonly Environment $environment,
        private readonly Instance $instance,
        private readonly LogFile $log,
        private readonly int $mark,
        private readonly string $output = '',
    ) {
        $count = 0;
        if ($instance instanceof Simulator && $instance->regionsDirectory() !== '') {
            foreach (glob($instance->regionsDirectory() . '/*.ini') ?: [] as $file) {
                $count += (int) preg_match_all('/^[ \t]*\[/m', (string) file_get_contents($file));
            }
        }
        $this->regions = max(1, $count);
    }

    /** Show the steps not shown yet. */
    public function show(): void
    {
        $steps = [];
        if ($this->output !== '' && is_file($this->output)) {
            array_push($steps, ...StartupProgress::steps((string) file_get_contents($this->output), $this->regions, true));
        }
        array_push($steps, ...StartupProgress::steps($this->log->since($this->mark), $this->regions));
        foreach ($steps as $step) {
            if (!isset($this->shown[$step])) {
                $this->shown[$step] = true;
                $this->environment->reporter->step($step);
            }
        }
    }

    /**
     * End of the progress. The regions announce themselves to the grid some seconds after their configuration is loaded: the
     * steps keep showing until all of them did (or for a minute, or while the instance runs), then no more.
     */
    public function finish(): void
    {
        for ($tick = 0; $tick < 120; $tick++) {
            $this->show();
            if (preg_match_all('/\[GRID SERVICE\]: Region .* registered at/', $this->log->since($this->mark)) >= $this->regions) {
                break;
            }
            if (!$this->instance->isRunning()) {
                break;
            }
            $this->environment->clock->sleep(0.5);
        }
        $this->show();
        $this->environment->reporter->stepEnd();
    }
}
