<?php

declare(strict_types=1);

namespace OpenSim\Services;

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Models\Region;
use OpenSim\Runtime\Environment;
use OpenSim\Scheduling\Scheduler;
use OpenSim\Scheduling\Task;

/**
 * The tasks of the search of a grid: read the snapshots of the registered simulators (`search-parse`, once a minute: a host that is
 * due is read, the others are left alone), and read the events of the calendar (`events-parse`, every 55 minutes).
 *
 *     SearchTasks::add($scheduler, $environment, $config);
 */
final class SearchTasks
{
    /** Seconds between two looks at the registered simulators. */
    public const PARSE_EVERY = 60;

    /** Seconds between two reads of the calendar of events. */
    public const EVENTS_EVERY = 3300;

    /** The calendar the events are read from unless the grid says another one (`HypEventsURL` of `[Helpers]`). */
    public const EVENTS_SOURCE = 'https://2do.directory/events';

    /** Add the tasks that the grid can run: the ones of the search need its search database. */
    public static function add(Scheduler $scheduler, Environment $environment, GridConfig $config): void
    {
        $db = Connections::pdo($config, Database::Search);
        if ($db === null || !$db->connected) {
            return;
        }
        $regions = Region::searchTable($db);
        $scheduler->add(
            new Task(
                'search-parse',
                self::PARSE_EVERY,
                static function () use ($db, $regions, $environment): string {
                    $registry = new SearchRegistry($db, $regions, $environment->clock);
                    $hosts = (new SearchParser($registry, $db, $regions, $environment->http))->parse();

                    return $hosts === 0 ? 'no simulator due' : "$hosts simulator(s) read";
                },
                'Read the snapshots of the registered simulators',
            ),
        );
        $source = rtrim($config->find('Helpers', 'HypEventsURL') ?? self::EVENTS_SOURCE, '/');
        $scheduler->add(
            new Task(
                'events-parse',
                self::EVENTS_EVERY,
                static function () use ($db, $source, $environment): string {
                    $kept = (new EventsParser($db, $source, $environment->http, clock: $environment->clock))->parse();

                    $kept < 0 && throw new \RuntimeException("the calendar $source did not answer");

                    return "$kept event(s) kept";
                },
                'Read the events of the calendar',
            ),
        );
    }
}
