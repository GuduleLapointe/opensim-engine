<?php

declare(strict_types=1);

namespace OpenSim\Config;

/**
 * A connection string of OpenSimulator (`Data Source=localhost;Database=opensim;User ID=opensim;Password=secret;Old Guids=true;`):
 * read from a config, written into one.
 */
final class ConnectionString
{
    /**
     * @param string $source the Data Source as written (`localhost`, `db.example.org:3307`)
     * @param string $host the host of the Data Source
     * @param ?int $port its port when it has one
     */
    public function __construct(
        public readonly string $source,
        public readonly string $database,
        public readonly string $user,
        public readonly string $password,
        public readonly string $host = '',
        public readonly ?int $port = null,
    ) {
    }

    /** A connection string for a host that may carry its port; the source is the host as given. */
    public static function for(string $source, string $database, string $user, string $password): self
    {
        [$host, $port] = self::split($source);

        return new self($source, $database, $user, $password, $host, $port);
    }

    /** The one a text says, null when it has no database to connect to. */
    public static function parse(string $text): ?self
    {
        $pairs = self::pairs($text);
        $source = $pairs['Data Source'] ?? '';
        $database = $pairs['Database'] ?? '';
        if ($source === '' || $database === '') {
            return null;
        }

        return self::for($source, $database, $pairs['User ID'] ?? '', $pairs['Password'] ?? '');
    }

    /**
     * The `Name=value;` pairs of a text.
     *
     * @return array<string,string>
     */
    public static function pairs(string $text): array
    {
        $pairs = [];
        foreach (explode(';', trim($text, " \t\r\n\"")) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2 && trim($pair[0]) !== '') {
                $pairs[trim($pair[0])] = trim($pair[1]);
            }
        }

        return $pairs;
    }

    /** What is written in the config. */
    public function __toString(): string
    {
        return sprintf(
            'Data Source=%s;Database=%s;User ID=%s;Password=%s;Old Guids=true;',
            $this->source,
            $this->database,
            $this->user,
            $this->password,
        );
    }

    /** What tells one database from another. */
    public function key(): string
    {
        return "{$this->user}@{$this->source}/{$this->database}";
    }

    /** @return array{0:string,1:?int} */
    private static function split(string $source): array
    {
        if (preg_match('/^(.+):(\d+)$/', $source, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [$source, null];
    }
}
