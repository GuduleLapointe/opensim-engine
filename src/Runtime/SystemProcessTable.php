<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * The processes of this machine, from ps.
 */
final class SystemProcessTable implements ProcessTableInterface
{
    /** The programs that run an OpenSimulator assembly. */
    private const RUNTIMES = ['dotnet', 'mono', 'mono-sgen', 'mono-boehm'];

    public function all(): array
    {
        return self::parse((string) @shell_exec(self::command() . ' 2>/dev/null'));
    }

    /**
     * The command that lists the processes. The lines are not cut (`ww`): a command line is cut to the width of the terminal
     * when ps is not given one, and the width is the COLUMNS of the environment, which a console application sets (80), while an
     * instance has a long command line.
     */
    public static function command(): string
    {
        $user = PHP_OS_FAMILY === 'Linux' ? 'uname' : 'user';

        return "ps axww -o pid=,$user=,pcpu=,pmem=,rss=,args=";
    }

    /**
     * The OpenSimulator processes of the output of `ps -o pid=,user=,pcpu=,pmem=,rss=,args=`.
     *
     * @return list<Process>
     */
    public static function parse(string $output): array
    {
        $found = [];
        foreach (explode("\n", $output) as $line) {
            if (!preg_match('/^\s*(\d+)\s+(\S+)\s+([\d.,]+)\s+([\d.,]+)\s+(\d+)\s+(\S+)(.*)$/', $line, $m)) {
                continue;
            }
            // The program (dotnet, mono) started with a config file: the compiler of mono (mcs.exe) is not an instance
            if (
                !in_array(basename($m[6]), self::RUNTIMES, true) ||
                !str_contains($m[7], ' -inifile=') ||
                str_contains($m[7], 'mcs.exe')
            ) {
                continue;
            }
            $found[] = new Process(
                (int) $m[1],
                $m[2],
                (float) str_replace(',', '.', $m[3]),
                (float) str_replace(',', '.', $m[4]),
                (int) $m[5],
                $m[6] . $m[7],
            );
        }

        return $found;
    }

    public function alive(int $pid): bool
    {
        return trim((string) @shell_exec('ps -p ' . $pid . ' -o pid= 2>/dev/null')) !== '';
    }

    public function signal(int $pid, string $signal): bool
    {
        exec('kill -' . escapeshellarg($signal) . ' ' . $pid . ' 2>/dev/null', $output, $code);

        return $code === 0;
    }

    public function listening(): array
    {
        return self::parseBound((string) @shell_exec('netstat -an 2>/dev/null'));
    }

    /**
     * The ports a machine has bound, from the output of `netstat -an` (BSD '.port', Linux ':port'): the local port of a TCP socket
     * that listens and of a UDP socket. The connections to other machines (their port is theirs, the local one is temporary)
     * are not ports this machine keeps.
     *
     * @return list<int>
     */
    public static function parseBound(string $netstat): array
    {
        $ports = [];
        foreach (preg_split('/\R/', $netstat) ?: [] as $line) {
            $cols = preg_split('/\s+/', trim($line)) ?: [];
            $proto = $cols[0] ?? '';
            if (!str_starts_with($proto, 'tcp') && !str_starts_with($proto, 'udp')) {
                continue;
            }
            if (str_starts_with($proto, 'tcp') && !in_array('LISTEN', $cols, true)) {
                continue;
            }
            if (isset($cols[3]) && preg_match('/[.:](\d+)$/', $cols[3], $m)) {
                $ports[] = (int) $m[1];
            }
        }

        return $ports;
    }
}
