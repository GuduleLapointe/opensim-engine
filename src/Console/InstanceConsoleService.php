<?php

declare(strict_types=1);

namespace OpenSim\Console;

use OpenSim\OpenSim;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\SystemProcessTable;

/**
 * Asking a running instance from the code that sets things up (the accounts of a grid, its regions): whether it runs, and
 * lines sent to its console. As the user that runs the instances: a screen session is per user.
 */
final class InstanceConsoleService
{
    /** @var ?\Closure(string,string):bool how the application sends lines when it is not the user that runs the instances */
    private static ?\Closure $sender = null;

    /**
     * Send the lines through something else than this process (the command line of the application, which acts as the user
     * that runs the instances whoever calls it), instead of reaching the console from here.
     *
     * @param ?\Closure(string,string):bool $sender given the instance and the lines, tells whether they were sent
     */
    public static function sendThrough(?\Closure $sender): void
    {
        self::$sender = $sender;
    }

    /** Whether an instance runs, from the config file it was started with (the link in opensim.d or robust.d). */
    public static function running(string $ini, ?Environment $environment = null): bool
    {
        $processes = $environment?->processes ?? new SystemProcessTable();
        $real = realpath($ini) ?: $ini;
        foreach ($processes->all() as $process) {
            if ($process->runs($ini) || $process->runs($real)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Send lines to the console of an instance. An empty line answers a prompt with its default.
     *
     * @param string $instance the name of an enabled instance
     * @param string $lines one per line
     * @return bool false when the instance is not running or cannot be reached
     */
    public static function send(string $instance, string $lines, ?Environment $environment = null): bool
    {
        if (self::$sender !== null && $environment === null) {
            return (self::$sender)($instance, $lines);
        }
        $environment ??= OpenSim::environment();
        $found = OpenSim::instance($environment, $instance);
        if ($found === null) {
            return false;
        }
        $console = $found->console();
        // The lines of a text end with a line break: no last empty line is made of it
        $list = explode("\n", rtrim($lines, "\n") === $lines ? $lines : substr($lines, 0, -1));
        if ($console instanceof RestConsole) {
            return $console->lines($list) !== null;
        }
        if (!$console->available()) {
            return false;
        }
        foreach ($list as $line) {
            $console->send($line);
            // A screen takes a line at a time
            $environment->clock->sleep(1);
        }

        return true;
    }
}
