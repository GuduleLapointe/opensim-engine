<?php

declare(strict_types=1);

namespace OpenSim\Console;

/**
 * The remote console of an instance (opensim-rest-php), reached at the local address whatever its config says: this is for
 * the instances of this machine.
 */
final class RestConsole implements ConsoleInterface
{
    /** Seconds to wait for the console to be ready again after a line. */
    public const WAIT = 5.0;

    public function __construct(
        private readonly int $port,
        private readonly string $user,
        private readonly string $password,
        private readonly string $host = '127.0.0.1',
    ) {}

    /** A session of the console, null when the instance does not answer. */
    public function session(): ?\OpenSim_Rest
    {
        $rest = new \OpenSim_Rest([
            'uri' => "http://{$this->host}:{$this->port}",
            'ConsoleUser' => $this->user,
            'ConsolePass' => $this->password,
        ]);

        return $rest->connected() ? $rest : null;
    }

    public function available(): bool
    {
        return $this->session() !== null;
    }

    public function send(string $line): bool
    {
        return $this->ask($line) !== null;
    }

    public function ask(string $line): ?array
    {
        $answers = $this->lines([$line]);

        return $answers;
    }

    /**
     * Several lines in one session, each one waiting for the console to be ready again.
     *
     * @param list<string> $lines
     * @return list<string>|null what the console wrote, null when the instance does not answer
     */
    public function lines(array $lines, float $wait = self::WAIT): ?array
    {
        $rest = $this->session();
        if ($rest === null) {
            return null;
        }
        $written = [];
        foreach ($lines as $line) {
            $result = $rest->command($line, $wait);
            array_push($written, ...$result['lines']);
            if ($result['closed']) {
                break;
            }
        }
        $rest->close();

        return $written;
    }
}
