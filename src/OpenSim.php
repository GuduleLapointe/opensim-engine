<?php

declare(strict_types=1);

namespace OpenSim;

use OpenSim\Config\Layout;
use OpenSim\Models\Grid;
use OpenSim\Controllers\AbstractController;
use OpenSim\Controllers\OpenSimController;
use OpenSim\Controllers\RobustController;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\ReporterInterface;
use OpenSim\Runtime\InstanceResult;
use OpenSim\Runtime\Status;

/**
 * The entry point of the front-ends (the command line, the administration, WordPress): what they ask of this machine, by the
 * name of what they mean. A target is a grid (`vonda`: the services of the grid, its Robust), a simulator (`vonda_welcome`), a
 * config file; none means every enabled instance. Restarting the services of a grid does not need its simulators to restart:
 * they are included when `simulators` is asked.
 *
 *     OpenSim::start('vonda');                          // the Robust of the grid
 *     OpenSim::restart('vonda', simulators: true);      // and the simulators that depend on it
 *     OpenSim::stop('vonda_welcome', now: true);
 *     OpenSim::status()->up();
 *
 * Whatever is done is done by the models (`Grid`, `Simulator`) and their controllers, which this finds from the name.
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
     * @param bool $simulators the enabled simulators of the grids named are started too
     * @return list<InstanceResult>
     */
    public static function start(
        string|array|null $targets = null,
        ?Environment $environment = null,
        bool $simulators = false,
    ): array {
        $environment ??= self::environment();
        $results = [];
        foreach (self::resolve($environment, $targets, $results, $simulators) as $instance) {
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
     * @param bool $simulators the enabled simulators of the grids named are stopped too
     * @return list<InstanceResult>
     */
    public static function stop(
        string|array|null $targets = null,
        bool $now = false,
        ?Environment $environment = null,
        bool $simulators = false,
    ): array {
        $environment ??= self::environment();
        $results = [];
        foreach (self::stopOrder(self::resolve($environment, $targets, $results, $simulators)) as $instance) {
            $results[] = $instance->stop($now);
        }

        return $results;
    }

    /**
     * Restart what the targets name: everything stops, then everything starts.
     *
     * @param string|list<string>|null $targets none for every enabled instance
     * @param bool $now stop without warning the users
     * @param bool $simulators the enabled simulators of the grids named restart too
     * @return list<InstanceResult>
     */
    public static function restart(
        string|array|null $targets = null,
        bool $now = false,
        ?Environment $environment = null,
        bool $simulators = false,
    ): array {
        $environment ??= self::environment();
        $results = [];
        $instances = self::resolve($environment, $targets, $results, $simulators);
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

        return new Status(self::enabled($environment), $processes);
    }

    /**
     * The enabled instances of this machine: the configs linked in `robust.d` and `opensim.d`, the Robusts first.
     *
     * @return list<AbstractController>
     */
    public static function enabled(Environment $environment): array
    {
        $etc = rtrim($environment->layout->etcRoot(), '/');
        $instances = [];
        $seen = [];
        foreach (
            [
                RobustController::class => ['robust.d', 'robust-enabled'],
                OpenSimController::class => ['opensim.d', 'simulators-enabled'],
            ]
            as $class => $folders
        ) {
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
     * One instance by what names it: a config file, or the name of an enabled instance (the one of a grid is its Robust).
     */
    public static function instance(Environment $environment, string $target): ?AbstractController
    {
        $target = trim($target);
        if ($target === '') {
            return null;
        }
        // A config file
        if (str_ends_with($target, '.ini') && is_file($target)) {
            $real = realpath($target) ?: $target;

            return str_contains(strtolower($target), 'robust')
                ? new RobustController($environment, $real)
                : new OpenSimController($environment, $real);
        }
        $name = AbstractController::cleanName($target);
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
     * @param list<InstanceResult> $results where the targets that name nothing are told
     * @param bool $simulators the enabled simulators of the grids named come after their Robust
     * @return list<AbstractController>
     */
    private static function resolve(
        Environment $environment,
        string|array|null $targets,
        array &$results,
        bool $simulators = false,
    ): array {
        $targets = array_values(array_filter((array) $targets, static fn($t): bool => trim((string) $t) !== ''));
        if ($targets === []) {
            return self::enabled($environment);
        }
        $instances = [];
        $seen = [];
        $add = static function (AbstractController $instance) use (&$instances, &$seen): void {
            if (!isset($seen[$instance->name()])) {
                $seen[$instance->name()] = true;
                $instances[] = $instance;
            }
        };
        foreach ($targets as $target) {
            $found = self::instance($environment, (string) $target);
            // A standalone has no Robust of its name: the grid is its simulator
            $standalone = $found === null ? Grid::find($environment, (string) $target) : null;
            if ($standalone?->isStandalone() === true) {
                $own = $standalone->instances();
                array_map($add, $own);
                if ($own === []) {
                    $message = "the standalone $target has no simulator enabled";
                    $environment->reporter->error($message);
                    $results[] = InstanceResult::failed((string) $target, $message);
                }

                continue;
            }
            if ($found === null) {
                $message = 'no ini file found for ' . $target;
                $environment->reporter->error($message);
                $results[] = InstanceResult::failed((string) $target, $message);

                continue;
            }
            $add($found);
            if ($simulators && $found instanceof RobustController) {
                foreach ($found->grid()?->enabledSimulators() ?? [] as $simulator) {
                    $add($simulator->controller());
                }
            }
        }

        return $instances;
    }

    /**
     * The order to stop instances in: the simulators, then the Robusts they depend on.
     *
     * @param list<AbstractController> $instances
     * @return list<AbstractController>
     */
    private static function stopOrder(array $instances): array
    {
        return [
            ...array_filter($instances, static fn(AbstractController $i): bool => !($i instanceof RobustController)),
            ...array_filter($instances, static fn(AbstractController $i): bool => $i instanceof RobustController),
        ];
    }
}
