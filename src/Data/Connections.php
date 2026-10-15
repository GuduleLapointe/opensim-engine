<?php

declare(strict_types=1);

namespace OpenSim\Data;

use OpenSim\Config\GridConfig;
use OpenSim\Provisioning\SqlRunner;

/**
 * The connections the models read and write with: one OSPDO for each database, opened when it is first needed and kept.
 * A model asks for the database it needs (a Database, or the section of a service of Robust) and gets null when the grid does not
 * say it or when it cannot be reached.
 */
final class Connections
{
    /** @var array<string,\OSPDO> */
    private static array $handles = [];

    /** @var array<string,string> why a database could not be reached, by the key of its connection */
    private static array $failures = [];

    /** @var ?\Closure(Database|string,?string,?\OpenSim\Config\ConnectionString):?\OSPDO */
    private static ?\Closure $factory = null;

    /**
     * @param Database|string $what a database, or the section of a service of Robust (UserAccountService, GridUserService...)
     * @param ?string $simulator for the databases of a simulator
     */
    public static function pdo(GridConfig $config, Database|string $what, ?string $simulator = null): ?\OSPDO
    {
        $connection = self::connection($config, $what, $simulator);
        if (self::$factory !== null) {
            return (self::$factory)($what, $simulator, $connection);
        }
        if ($connection === null) {
            return null;
        }
        $pdo = self::$handles[$connection->key()] ??= new \OSPDO($connection->credentials() + ['log' => false]);
        if (!$pdo->connected) {
            self::$failures[$connection->key()] = SqlRunner::describe($pdo->error);

            return null;
        }

        return $pdo;
    }

    /** Why a database could not be reached, null when it could or was not asked for. */
    public static function reason(GridConfig $config, Database|string $what, ?string $simulator = null): ?string
    {
        $key = self::key($config, $what, $simulator);

        return $key === null ? null : (self::$failures[$key] ?? null);
    }

    /** What tells one database from another, null when the grid does not say it. */
    public static function key(GridConfig $config, Database|string $what, ?string $simulator = null): ?string
    {
        return self::connection($config, $what, $simulator)?->key();
    }

    /** Use other connections (a test's), or the real ones again with null. */
    public static function using(?\Closure $factory): void
    {
        self::$factory = $factory;
        self::$handles = [];
        self::$failures = [];
    }

    private static function connection(GridConfig $config, Database|string $what, ?string $simulator): ?\OpenSim\Config\ConnectionString
    {
        return $what instanceof Database ? $what->connection($config, $simulator) : Database::service($config, $what);
    }
}
