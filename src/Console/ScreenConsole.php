<?php

declare(strict_types=1);

namespace OpenSim\Console;

/**
 * The console of an instance run in a screen session (the sessions are per user: the one that runs the instances).
 */
final class ScreenConsole implements ScreenInterface
{
    public function __construct(private readonly string $name)
    {
    }

    /** Drop the dead sessions, so that they are not taken for a running one. */
    public static function wipe(): void
    {
        exec('screen -wipe >/dev/null 2>&1');
    }

    /** Whether a live session of this name exists (the name is the whole name, not the start of one). */
    public function exists(): bool
    {
        $list = (string) @shell_exec('screen -ls 2>/dev/null');
        foreach (explode("\n", $list) as $line) {
            if (preg_match('/^\s*\d+\.' . preg_quote($this->name, '/') . '\s/', $line) && !str_contains($line, 'Dead')) {
                return true;
            }
        }

        return false;
    }

    public function available(): bool
    {
        return $this->exists();
    }

    /**
     * Type a line exactly as given: through a register of screen, read from a file, so that nothing in it is taken for an
     * escape, and it does not show in the arguments of a process. An empty line is a bare Enter.
     */
    public function send(string $line): bool
    {
        if (!$this->exists() || ($file = tempnam(sys_get_temp_dir(), 'osconsole')) === false) {
            return false;
        }
        file_put_contents($file, $line . "\n");
        $name = escapeshellarg($this->name);
        exec("screen -S $name -X readreg p " . escapeshellarg($file) . " >/dev/null 2>&1 && screen -S $name -X paste p >/dev/null 2>&1", $output, $code);
        // screen reads the file when it gets the command, not when we return
        usleep(500_000);
        @unlink($file);

        return $code === 0;
    }

    /** What a command prints in the session: what follows the last line showing the command, in the scrollback. */
    public function ask(string $line): ?array
    {
        if (!$this->send($line)) {
            return null;
        }
        sleep(2);
        $text = $this->screenText();
        $answer = [];
        foreach (explode("\n", $text) as $row) {
            if (str_contains($row, $line)) {
                $answer = [];

                continue;
            }
            $answer[] = $row;
        }
        while ($answer !== [] && trim((string) end($answer)) === '') {
            array_pop($answer);
        }

        return $answer === [] ? null : $answer;
    }

    /** The scrollback of the session, as the screen shows it. */
    public function screenText(): string
    {
        if (($file = tempnam(sys_get_temp_dir(), 'osscreen')) === false) {
            return '';
        }
        exec('screen -S ' . escapeshellarg($this->name) . ' -X hardcopy -h ' . escapeshellarg($file) . ' >/dev/null 2>&1');
        usleep(500_000);
        $text = (string) @file_get_contents($file);
        @unlink($file);

        return $text;
    }

    /** The log of the session written as it comes, not by blocks. */
    public function flushLog(): void
    {
        exec('screen -S ' . escapeshellarg($this->name) . ' -X logfile flush 1 >/dev/null 2>&1');
    }

    /** Attach to the session: Ctrl-A D leaves it running. Returns the status of screen. */
    public function attach(): int
    {
        $process = proc_open(['screen', '-x', $this->name], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes);

        return is_resource($process) ? proc_close($process) : 1;
    }
}
