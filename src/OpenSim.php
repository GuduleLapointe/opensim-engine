<?php

declare(strict_types=1);

namespace OpenSim;

use OpenSim\Config\Layout;
use OpenSim\Models\Grid;
use OpenSim\Models\Instance;
use OpenSim\Models\Robust;
use OpenSim\Models\Simulator;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\InstanceStatus;
use OpenSim\Runtime\ReporterInterface;
use OpenSim\Runtime\Result;
use OpenSim\Runtime\Status;

/**
 * The entry point of the front-ends (the command line, the administration, WordPress): what they ask of this machine, by the
 * name of what they mean. A target is a grid (`vonda`: its Robust and its simulators), a simulator (`vonda_welcome`), the Robust
 * of a grid alone (`robust:vonda`), a config file; none means every enabled instance.
 *
 *     OpenSim::start('vonda');
 *     OpenSim::stop('vonda_welcome', now: true);
 *     OpenSim::status()->up();
 *
 * Whatever is done is done by the models (`Grid`, `Simulator`, `Robust`), which this finds from the name.
 */
final class OpenSim
{
    /** The environment of this machine, the install found in its usual place. */
    public static function environment(?ReporterInterface $reporter = null, ?Layout $layout = null): Environment
    {
        return Environment::system($layout ?? Layout::load(), $reporter);
    }

    /**
     * Start what the targets name, Robust first. An instance that runs is left alone.
     *
     * @param string|list<string>|null $targets none for every enabled instance
     * @return list<Result>
     */
    public static function start(string|array|null $targets = null, ?Environment $environment = null): array
    {
        $environment ??= self::environment();
        $results = [];
        foreach (self::resolve($environment, $targets, $results) as $instance) {
            $result = $instance->start();
            $results[] = $result;
        }

        return $results;
    }

    /**
     * Stop what the targets name, the simulators first.
     *
     * @param string|list<string>|null $targets none for every enabled instance
     * @param bool $now stop without warning the users
     * @return list<Result>
     */
    public static function stop(string|array|null $targets = null, bool $now = false, ?Environment $environment = null): array
    {
        $environment ??= self::environment();
        $results = [];
        foreach (self::stopOrder(self::resolve($environment, $targets, $results)) as $instance) {
            $results[] = $instance->stop($now);
        }

        return $results;
    }

    /**
     * Restart what the targets name: everything stops, then everything starts.
     *
     * @param string|list<string>|null $targets none for every enabled instance
     * @param bool $now stop without warning the users
     * @return list<Result>
     */
    public static function restart(string|array|null $targets = null, bool $now = false, ?Environment $environment = null): array
    {
        $environment ??= self::environment();
        $results = [];
        $instances = self::resolve($environment, $targets, $results);
        foreach (self::stopOrder($instances) as $instance) {
            $instance->stop($now);
        }
        foreach ($instances as $instance) {
            $results[] = $instance->start();
        }

        return $results;
    }

    /** The enabled instances of this machine, running or not, and what the OpenSimulator processes use. */
    public static function status(?Environment $environment = null): Status
    {
        $environment ??= self::environment();
        $processes = $environment->processes->all();
        $instances = [];
        foreach (self::enabled($environment) as $instance) {
            $real = realpath($instance->ini()) ?: $instance->ini();
            $found = null;
            foreach ($processes as $process) {
                if ($process->runs($instance->ini()) || $process->runs($real)) {
                    $found = $process;

                    break;
                }
            }
            $instances[] = new InstanceStatus($instance, $found);
        }

        return new Status($instances, $processes);
    }

    /**
     * The enabled instances of this machine: the configs linked in `robust.d` and `opensim.d`, the Robusts first.
     *
     * @return list<Instance>
     */
    public static function enabled(Environment $environment): array
    {
        $etc = rtrim($environment->layout->etcRoot(), '/');
        $instances = [];
        $seen = [];
        foreach ([Robust::class => ['robust.d', 'robust-enabled'], Simulator::class => ['opensim.d', 'simulators-enabled']] as $class => $folders) {
            foreach ($folders as $folder) {
                $files = glob("$etc/$folder/*.ini") ?: [];
                sort($files);
                foreach ($files as $file) {
                    $real = realpath($file) ?: $file;
                    if (!isset($seen[$real])) {
                        $seen[$real] = true;
                        $instances[] = new $class($environment, $file);
                    }
                }
            }
        }

        return $instances;
    }

    /**
     * The instances a target names, in the order they start: a grid is its Robust and its simulators, anything else is one
     * instance (see instance()).
     *
     * @return list<Instance>|null null when the target is none of them
     */
    public static function find(Environment $environment, string $target): ?array
    {
        $target = trim($target);
        // A grid: its Robust and its simulators
        if ($target !== '' && !str_ends_with($target, '.ini') && !str_starts_with($target, 'robust:')
            && ($grid = Grid::find($environment, $target)) !== null && $grid->instances() !== []) {
            return $grid->instances();
        }
        $instance = self::instance($environment, $target);

        return $instance === null ? null : [$instance];
    }

    /**
     * One instance by what names it: a config file, `robust:<grid>` (the Robust of a grid), or the name of an enabled instance
     * (the one of a grid is its Robust).
     */
    public static function instance(Environment $environment, string $target): ?Instance
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }
        // A config file
        if (str_ends_with($target, '.ini') && is_file($target)) {
            $real = realpath($target) ?: $target;

            return str_contains(strtolower($target), 'robust') ? new Robust($environment, $real) : new Simulator($environment, $real);
        }
        // The Robust of a grid alone
        if (str_starts_with($target, 'robust:')) {
            $robust = Grid::find($environment, substr($target, 7))?->robust();

            return $robust !== null && $robust->isEnabled() ? $robust : null;
        }
        $name = Instance::cleanName($target);
        foreach (self::enabled($environment) as $instance) {
            if ($instance->name() === $name) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * The instances to act on: what the targets name, or every enabled instance. A target that names nothing is a failed result.
     *
     * @param string|list<string>|null $targets
     * @param list<Result> $results where the targets that name nothing are told
     * @return list<Instance>
     */
    private static function resolve(Environment $environment, string|array|null $targets, array &$results): array
    {
        $targets = array_values(array_filter((array) $targets, static fn($t): bool => trim((string) $t) !== ''));
        if ($targets === []) {
            return self::enabled($environment);
        }
        $instances = [];
        $seen = [];
        foreach ($targets as $target) {
            $found = self::find($environment, (string) $target);
            if ($found === null) {
                $message = 'no ini file found for ' . $target;
                $environment->reporter->error($message);
                $results[] = Result::failed((string) $target, $message);

                continue;
            }
            foreach ($found as $instance) {
                if (!isset($seen[$instance->name()])) {
                    $seen[$instance->name()] = true;
                    $instances[] = $instance;
                }
            }
        }

        return $instances;
    }

    /**
     * The order to stop instances in: the simulators, then the Robusts they depend on.
     *
     * @param list<Instance> $instances
     * @return list<Instance>
     */
    private static function stopOrder(array $instances): array
    {
        return [
            ...array_filter($instances, static fn(Instance $i): bool => !$i instanceof Robust),
            ...array_filter($instances, static fn(Instance $i): bool => $i instanceof Robust),
        ];
    }
}
