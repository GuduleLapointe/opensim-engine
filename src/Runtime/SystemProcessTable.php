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
        $user = PHP_OS_FAMILY === 'Linux' ? 'uname' : 'user';
        $output = (string) @shell_exec("ps -axo pid=,$user=,pcpu=,pmem=,rss=,args= 2>/dev/null");

        return self::parse($output);
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
            if (!in_array(basename($m[6]), self::RUNTIMES, true) || !str_contains($m[7], ' -inifile=') || str_contains($m[7], 'mcs.exe')) {
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
}
