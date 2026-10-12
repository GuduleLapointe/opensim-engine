<?php

declare(strict_types=1);

namespace OpenSim\Network;

use OpenSim\Config\ConfigReader;

/**
 * The ports an instance (Robust or simulator) listens on, read from its config
 * and, for a simulator, from the descriptions of its regions, with what each
 * one is for and who has to reach it:
 *
 *  - public:  the viewers, the other grids, the other simulators
 *  - private: the simulators of the grid only (Robust private port, without
 *             any authentication: never for the world)
 *  - console: the REST console, which gives every right on the instance
 *  - off:     kept for a console that is not enabled
 *
 * The region ports are UDP: they are the ones the viewers send to, and the
 * number is the one the viewers get, so it cannot differ outside.
 */
final class InstancePorts
{
    /**
     * The ports of the instance whose config file is given.
     *
     * @param ?\Closure(string):string $read reads a file (the whole text, empty for one that is not there): the way the caller
     *                                        reads the files it cannot read itself; default, the file as it is
     * @return list<array{port:int,proto:string,role:string,use:string}>
     */
    public static function of(string $ini, ?\Closure $read = null): array
    {
        $text = $read !== null ? $read($ini) : self::read($ini);
        $read ??= static fn(string $file): string => self::read($file);
        $values = self::values($text);
        $const = $values['const'] ?? [];
        $expand = static function (string $value) use ($const): string {
            return (string) preg_replace_callback(
                '/\$\{Const\|([A-Za-z0-9_]+)\}/',
                static fn(array $m): string => (string) ($const[strtolower($m[1])] ?? ''),
                $value,
            );
        };
        $first = static fn(string $section, string $key): ?string => isset($values[$section][$key])
            ? $expand($values[$section][$key])
            : null;

        $uses = [];
        $add = static function (?string $port, string $proto, string $role, string $use) use (&$uses): void {
            if ($port !== null && ctype_digit(trim($port)) && (int) $port > 0) {
                $uses[] = ['port' => (int) $port, 'proto' => $proto, 'role' => $role, 'use' => $use];
            }
        };

        // A console is a REST console when it has its port, a user and a password
        $console = static function (string $section, string $portKey) use (
            $values,
            $expand,
            $text,
            &$uses,
            $add,
        ): void {
            $port = isset($values[$section][$portKey]) ? $expand($values[$section][$portKey]) : null;
            $enabled =
                $port !== null &&
                ctype_digit($port) &&
                (int) $port > 0 &&
                !empty($values[$section]['consoleuser'] ?? '') &&
                !empty($values[$section]['consolepass'] ?? '');
            if ($enabled) {
                $add($port, 'tcp', 'REST console (every right on the instance)', 'console');

                return;
            }
            // Its port kept in the file, for when it is enabled
            if (
                $port === null &&
                preg_match('/^\s*;+\s*' . $portKey . '\s*=\s*"?(\d+)/mi', $text, $m) &&
                (int) $m[1] > 0
            ) {
                $add($m[1], 'tcp', 'REST console (not enabled)', 'off');
            }
        };

        if (isset($values['serviceconnectors']) || isset($values['servicelist']) || isset($values['gridinfoservice'])) {
            // Robust
            $public = $first('const', 'publicport');
            $private = $first('const', 'privateport');
            $add($public, 'tcp', 'public port of the grid: viewers, other grids', 'public');
            $add($private, 'tcp', 'private port of the grid: its simulators only', 'private');
            foreach (array_values($values['servicelist'] ?? []) as $connector) {
                $port = explode('/', $expand($connector), 2)[0];
                $port = preg_replace('/^.*@/', '', $port);
                if (ctype_digit($port) && $port !== $public && $port !== $private) {
                    $add($port, 'tcp', 'additional service', 'private');
                }
            }
            $console('network', 'consoleport');
        } else {
            // A simulator
            $add(
                $first('network', 'http_listener_port'),
                'tcp',
                'HTTP of the simulator: viewers, grid, neighbours',
                'public',
            );
            $add($first('network', 'http_listener_sslport'), 'tcp', 'HTTPS of the simulator', 'public');
            $add($first('xmlrpc', 'xmlrpcport'), 'tcp', 'XML-RPC of the simulator (scripts)', 'public');
            $console('network', 'console_port');

            $regions = $first('startup', 'regionload_regionsdir');
            foreach ($regions !== null ? (glob(rtrim($regions, '/') . '/*.ini') ?: []) : [] as $file) {
                $region = $read($file);
                if (preg_match_all('/^\s*\[([^\]]+)\]\s*$(.*?)(?=^\s*\[|\z)/ms', $region, $blocks, PREG_SET_ORDER)) {
                    foreach ($blocks as $block) {
                        if (preg_match('/^\s*InternalPort\s*=\s*"?(\d+)/mi', $block[2], $m)) {
                            $add($m[1], 'udp', 'region ' . trim($block[1]) . ': viewers', 'public');
                        }
                    }
                }
            }
        }

        usort($uses, static fn(array $a, array $b): int => [$a['port'], $a['proto']] <=> [$b['port'], $b['proto']]);

        return $uses;
    }

    /** A file as it is, with Unix line endings; empty when it is not there. */
    private static function read(string $file): string
    {
        $text = is_file($file) ? @file_get_contents($file) : '';

        return str_replace("\r\n", "\n", $text === false ? '' : $text);
    }

    /**
     * The values of an ini file by section and key, names in lower case.
     *
     * @return array<string,array<string,string>>
     */
    private static function values(string $text): array
    {
        $values = [];
        foreach (ConfigReader::parse($text) as $section => $keys) {
            $values[strtolower($section)] = array_change_key_case($keys, CASE_LOWER);
        }

        return $values;
    }
}
