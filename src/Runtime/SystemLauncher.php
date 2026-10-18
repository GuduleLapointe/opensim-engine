<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

use OpenSim\Console\ScreenConsole;

/**
 * Starts the instances on this machine: detached (nohup) when they have a remote console, in a screen session otherwise.
 */
final class SystemLauncher implements LauncherInterface
{
    public function launch(LaunchSpec $spec): bool
    {
        $command = implode(' ', array_map('escapeshellarg', $spec->command));
        if ($spec->environment !== []) {
            $command =
                'env ' .
                implode(
                    ' ',
                    array_map(
                        static fn(string $name, string $value): string => escapeshellarg("$name=$value"),
                        array_keys($spec->environment),
                        $spec->environment,
                    ),
                ) .
                ' ' .
                $command;
        }
        $output = $spec->output !== '' ? escapeshellarg($spec->output) : '/dev/null';
        $script = 'cd ' . escapeshellarg($spec->directory) . ' || exit 1; ';
        if ($spec->bigStack) {
            $script .= 'ulimit -s 1048576 2>/dev/null; ';
        }
        if ($spec->screen) {
            // The output of the session is kept, so that an early crash can be reported
            $script .= 'screen -L -Logfile ' . $output . ' -S ' . escapeshellarg($spec->name) . ' -d -m ' . $command;
        } else {
            // No terminal session: the output goes to a file, the console is the remote one
            $script .= '(nohup ' . $command . ' >' . $output . ' 2>&1 &)';
        }
        exec($script . ' >/dev/null 2>&1', $lines, $code);
        if ($spec->screen && $code === 0) {
            (new ScreenConsole($spec->name))->flushLog();
        }

        return $code === 0;
    }
}
