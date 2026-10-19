<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Controllers\AbstractController;

/**
 * The connectors a Robust loads, as their class names: what its ServerMain reads, the non-empty values of `[ServiceList]` and
 * of `[Startup] ServiceConnectors`, written "[config@]port/assembly:class" or "assembly:class". Every service its config lists
 * is one the instance must load.
 */
final class RobustServices
{
    /** @return list<string> */
    public static function of(AbstractController $instance): array
    {
        $values = array_values($instance->settings()['ServiceList'] ?? []);
        array_push($values, ...preg_split('/[,\s]+/', $instance->setting('Startup', 'ServiceConnectors')) ?: []);

        $classes = [];
        foreach ($values as $value) {
            $value = trim((string) $value, " \t\"");
            if ($value === '') {
                continue;
            }
            $connector = str_contains($value, '/') ? substr($value, strpos($value, '/') + 1) : $value;
            $classes[] = str_contains($connector, ':')
                ? substr($connector, (int) strrpos($connector, ':') + 1)
                : $connector;
        }

        return array_values(array_unique($classes));
    }
}
