<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Controllers\AbstractController;
use OpenSim\Controllers\OpenSimController;

/**
 * Stops an instance: the users of a simulator are warned and counted down to the shutdown (and the wait is cut short when nobody
 * is there), the console is asked to shut down, the process is waited for, then forced if it does not go. The ports it had
 * mapped are closed.
 */
final class StopService
{
    /** Seconds the users have to leave a simulator, and how many times they are warned. */
    public const DELAY = 120;
    public const WARNINGS = 3;

    /** Seconds an instance has to stop once asked to, and a forced one to disappear. */
    private const TIMEOUT = 120;

    private readonly UserCounter $users;

    public function __construct(private readonly Environment $environment, ?UserCounter $users = null)
    {
        $this->users = $users ?? new UserCounter($environment);
    }

    /** @param bool $now stop without warning the users */
    public function stop(AbstractController $instance, bool $now = false): InstanceResult
    {
        $env = $this->environment;
        $name = $instance->name();
        $console = $instance->console();
        $running = $instance->isRunning();

        // Only a running simulator has users to warn
        if ($instance instanceof OpenSimController && $running && $instance->executableBase() === 'OpenSim') {
            $this->warn($instance, $now ? 0 : self::DELAY);
        }
        $process = $instance->process();
        $env->reporter->debug("shutting down $name");
        if ($running) {
            $console->send('shutdown');
        }
        $stopped = $process === null;
        if ($process !== null) {
            $env->reporter->info("stopping $name");
            if ($this->waitExit($name, $process, self::TIMEOUT)) {
                $env->reporter->info("$name stopped");
                $stopped = true;
            } else {
                // Asked to shut down and not gone when the delay is over (a console busy with a long command, a hung
                // instance): it is forced
                $env->reporter->info("$name still running after " . self::TIMEOUT . 's, forcing it');
                if ($this->forceStop($name, $process)) {
                    $env->reporter->info("$name stopped (forced)");
                    $stopped = true;
                } else {
                    $env->reporter->error("$name still running after being killed");
                }
            }
        }
        $env->portMapping?->stop($name, $instance->ini(), $instance->ports());

        return match (true) {
            !$stopped => InstanceResult::failed($name, "$name still running after being killed"),
            $process !== null => new InstanceResult($name, Outcome::Stopped),
            default => new InstanceResult($name, Outcome::NotRunning),
        };
    }

    /** Warn the users of a simulator, counting down to the shutdown, unless nobody is there. */
    private function warn(OpenSimController $simulator, int $delay): void
    {
        $env = $this->environment;
        $name = $simulator->name();
        $warnings = $delay <= 10 ? 1 : self::WARNINGS;
        $warnings = $delay === 0 ? 0 : $warnings;

        // Nobody to warn: no reason to wait
        $nobody = false;
        if ($warnings > 0) {
            $state = $this->users->nobodyToWaitFor($simulator);
            if ($state['nobody']) {
                $env->reporter->info(
                    $state['unknown']
                        ? "$name: no answer about its users, nobody can be warned, stopping now"
                        : "$name: no user in its regions, stopping now",
                );
                $warnings = 0;
                $nobody = true;
            }
        }
        $console = $simulator->console();
        if ($warnings > 0) {
            // The console is in one region: the warnings are for all of them
            $console->send('change region root');
            $env->reporter->info("$name: warning its users, it stops in $delay seconds");
        }
        $interval = $warnings > 0 ? intdiv($delay, $warnings) : 0;
        for ($warn = 0; $warn < $warnings; $warn++) {
            $remaining = $delay - $warn * $interval;
            $console->send(
                "alert Attention: this simulator will be stopped in $remaining seconds. Please teleport to another region to avoid being disconnected.",
            );
            // The countdown is the time left before the shutdown, not before the next warning
            if (
                !$this->countdown(
                    $interval,
                    "Stopping $name",
                    $remaining - $interval,
                    fn(): bool => $this->users->nobodyToWaitFor($simulator)['nobody'],
                )
            ) {
                $env->reporter->info("$name: no user left in its regions, stopping now");
                $nobody = true;

                break;
            }
        }
        if (!$nobody) {
            $console->send('alert Shutting down now.');
        }
    }

    /**
     * Wait for a delay, counting it down.
     *
     * @param int $left seconds to wait
     * @param string $label what is waited for
     * @param int $offset seconds still to wait after this delay (the shown count is the total)
     * @param ?\Closure():bool $check tells the wait is useless when it succeeds (checked every 5 seconds)
     * @return bool true when the delay is over, false when the check succeeded
     */
    public function countdown(int $left, string $label, int $offset = 0, ?\Closure $check = null): bool
    {
        $env = $this->environment;
        $terminal = $env->reporter->isTerminal();
        $shown = false;
        $since = 0;
        while ($left > 0) {
            if ($terminal || !$shown) {
                $env->reporter->step('  ' . $label . ' in ' . ($left + $offset) . 's');
                $shown = true;
            }
            $tick = $terminal ? 1 : $left;
            if ($check !== null && $tick > 5) {
                $tick = 5;
            }
            $env->clock->sleep($tick);
            $left -= $tick;
            $since += $tick;
            if ($check !== null && $since >= 5 && $left > 0) {
                $since = 0;
                if ($check()) {
                    $env->reporter->stepEnd();

                    return false;
                }
            }
        }
        $env->reporter->stepEnd();

        return true;
    }

    /** Wait for the process of an instance to exit, showing what it does meanwhile (the last line of its log). */
    private function waitExit(string $name, Process $process, int $seconds): bool
    {
        $env = $this->environment;
        $log = $process->logFile() !== '' ? new LogFile($process->logFile()) : null;
        for ($tick = 0; $env->processes->alive($process->pid); $tick++) {
            if ($tick >= $seconds * 2) {
                $env->reporter->stepEnd();

                return false;
            }
            $last = $log?->lastLine();
            $text =
                "  Waiting for $name to stop" . ($env->reporter->isTerminal() ? ' (' . intdiv($tick, 2) . 's)' : '');
            $env->reporter->step($text . ($last !== null && $last !== '' ? ": $last" : ''));
            $env->clock->sleep(0.5);
        }
        $env->reporter->stepEnd();

        return true;
    }

    /** Stop what did not stop when it was asked to: TERM, then KILL when it is still there after a grace period. */
    private function forceStop(string $name, Process $process, int $grace = 15): bool
    {
        $this->environment->processes->signal($process->pid, 'TERM');
        if ($this->waitExit($name, $process, $grace)) {
            return true;
        }
        $this->environment->processes->signal($process->pid, 'KILL');

        return $this->waitExit($name, $process, 5);
    }
}
