<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Runtime\Environment;
use OpenSim\Runtime\Result;

/**
 * A grid: the folder `<etc>/grids/<nick>` that holds its Robust config, its description (`<nick>.conf`) and the configs of
 * its simulators (`sims/<name>.ini`). A grid is run from this machine (its Robust, its simulators) or only described here, its
 * Robust being elsewhere.
 *
 * Starting a grid starts its Robust, then its simulators; stopping it stops its simulators, then its Robust.
 */
final class Grid
{
    /** The files that hold the Robust config, the first that exists is the one. */
    private const ROBUST_FILES = ['Robust.HG.ini', 'Robust.ini'];

    private function __construct(
        private readonly Environment $environment,
        private readonly string $directory,
    ) {
    }

    /** The grid whose folder is given. */
    public static function at(Environment $environment, string $directory): self
    {
        return new self($environment, rtrim($directory, '/'));
    }

    /** The grid of this nick on this machine, null when there is none. */
    public static function find(Environment $environment, string $nick): ?self
    {
        $directory = rtrim($environment->layout->etcRoot(), '/') . "/grids/$nick";

        return $nick !== '' && preg_match('/^[A-Za-z0-9._-]+$/', $nick) && is_dir($directory) ? new self($environment, $directory) : null;
    }

    /**
     * The grids of this machine.
     *
     * @return list<self>
     */
    public static function all(Environment $environment): array
    {
        $grids = [];
        foreach (glob(rtrim($environment->layout->etcRoot(), '/') . '/grids/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $grids[] = new self($environment, $directory);
        }

        return $grids;
    }

    public function nick(): string
    {
        return basename($this->directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    /**
     * The settings of its description, `[Grid]` of `<nick>.conf`.
     *
     * @return array<string,string>
     */
    public function settings(): array
    {
        return \OpenSim_Config::read($this->directory . '/' . $this->nick() . '.conf')['Grid'] ?? [];
    }

    /** Whether its Robust is on another machine: the grid is only described here. */
    public function isRemote(): bool
    {
        return $this->robustIni() === null && in_array(strtolower(trim($this->settings()['Remote'] ?? '')), ['true', '1', 'yes'], true);
    }

    /** The config file of its Robust, null for a grid that has none (a remote grid). */
    public function robustIni(): ?string
    {
        foreach (self::ROBUST_FILES as $file) {
            if (is_file("{$this->directory}/$file")) {
                return "{$this->directory}/$file";
            }
        }

        return null;
    }

    /** Its Robust on this machine, null for a remote grid. Run with the link that enables it when it is enabled. */
    public function robust(): ?Robust
    {
        $file = $this->robustIni();
        if ($file === null) {
            return null;
        }
        $robust = new Robust($this->environment, $file, $this->nick());

        return $robust->isEnabled() ? new Robust($this->environment, $robust->link(), $this->nick()) : $robust;
    }

    /**
     * Its simulators, enabled or not, by name.
     *
     * @return list<Simulator>
     */
    public function simulators(): array
    {
        $files = glob($this->directory . '/sims/*.ini') ?: [];
        sort($files);
        $simulators = [];
        foreach ($files as $file) {
            $simulator = new Simulator($this->environment, $file);
            $simulators[] = $simulator->isEnabled() ? new Simulator($this->environment, $simulator->link()) : $simulator;
        }

        return $simulators;
    }

    /** @return list<Simulator> the simulators that start with the others */
    public function enabledSimulators(): array
    {
        return array_values(array_filter($this->simulators(), static fn(Simulator $s): bool => $s->isEnabled()));
    }

    /** One of its simulators by name. */
    public function simulator(string $name): ?Simulator
    {
        foreach ($this->simulators() as $simulator) {
            if ($simulator->name() === Instance::cleanName($name)) {
                return $simulator;
            }
        }

        return null;
    }

    /** Whether the grid is run from this machine: its Robust is enabled. */
    public function isEnabled(): bool
    {
        return $this->robust()?->isEnabled() ?? false;
    }

    /** Enable its Robust. */
    public function enable(): bool
    {
        return $this->robust()?->enable() ?? false;
    }

    public function disable(): void
    {
        $this->robust()?->disable();
    }

    /**
     * The instances to run, in the order they start: its Robust, then its enabled simulators.
     *
     * @return list<Instance>
     */
    public function instances(): array
    {
        $robust = $this->robust();

        return [...($robust !== null && $robust->isEnabled() ? [$robust] : []), ...$this->enabledSimulators()];
    }

    /**
     * Start the grid: its Robust, then its simulators. A simulator is not started when Robust did not come up.
     *
     * @return list<Result>
     */
    public function start(): array
    {
        $results = [];
        foreach ($this->instances() as $instance) {
            $result = $instance->start();
            $results[] = $result;
            if ($instance instanceof Robust && !$result->ok()) {
                break;
            }
        }

        return $results;
    }

    /**
     * Stop the grid: its simulators, then its Robust.
     *
     * @return list<Result>
     */
    public function stop(bool $now = false): array
    {
        return array_map(static fn(Instance $i): Result => $i->stop($now), array_reverse($this->instances()));
    }

    /**
     * Restart the grid: everything stops, then everything starts.
     *
     * @return list<Result>
     */
    public function restart(bool $now = false): array
    {
        return [...$this->stop($now), ...$this->start()];
    }
}
