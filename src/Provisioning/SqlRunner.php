<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

/**
 * Runs SQL statements through OSPDO and reports them as the MySQL client did, which the callers read: an exit code, the rows
 * (one line each, columns separated by tabs, NULL written as such) and "ERROR <number> (<state>): <message>".
 *
 * An access is the credentials of an account (host, port, user, pass, socket), or `own` for the person who runs: the options
 * file of their MySQL client (~/.my.cnf), else their login name through the local socket. With `sudo` the statements run as
 * root, in a PHP process started with sudo, the statements and credentials going through its standard input.
 */
final class SqlRunner
{
    /** Seconds to wait for the server. */
    public const TIMEOUT = 5;

    /**
     * @param array{host?:string,port?:?int,user?:string,pass?:string,socket?:?string,own?:bool,sudo?:bool} $access
     * @param ?string $database the database to use, none for a connection to the server
     * @return array{0:int,1:string,2:string} [exit code, rows, error]
     */
    public static function run(array $access, string $sql, ?string $database = null): array
    {
        if ($access['sudo'] ?? false) {
            return self::viaSudo($access, $sql, $database);
        }
        if (!extension_loaded('pdo_mysql')) {
            return [1, '', 'the PHP extension pdo_mysql is missing'];
        }
        if ($access['own'] ?? false) {
            $access = array_merge($access, self::own());
        }
        self::load();

        try {
            $pdo = \OSPDO::open([
                'host' => $access['host'] ?? 'localhost',
                'port' => $access['port'] ?? null,
                'socket' => $access['socket'] ?? null,
                'user' => $access['user'] ?? '',
                'pass' => $access['pass'] ?? '',
                'name' => $database,
                'timeout' => self::TIMEOUT,
            ]);

            return [0, self::rows($pdo, $sql), ''];
        } catch (\PDOException $e) {
            return [1, '', self::describe($e)];
        }
    }

    /**
     * The rows of the statements, one line each.
     *
     * @throws \PDOException
     */
    public static function rows(\PDO $pdo, string $sql): string
    {
        $statement = $pdo->query($sql);
        $multiple = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $lines = [];
        do {
            if ($statement->columnCount() > 0) {
                while (($row = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
                    $lines[] = implode("\t", array_map(self::cell(...), $row));
                }
            }
        } while ($multiple && $statement->nextRowset());

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    /** A value as the client writes it in batch mode: NULL as such, tabs, newlines and backslashes escaped. */
    public static function cell(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        return strtr((string) $value, ['\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\0" => '\\0']);
    }

    /** The error as the client writes it. */
    public static function describe(\PDOException $e): string
    {
        $state = (string) ($e->errorInfo[0] ?? 'HY000');
        $number = (int) ($e->errorInfo[1] ?? 0);
        $message = (string) ($e->errorInfo[2] ?? '');
        if ($number === 0 && preg_match('/SQLSTATE\[(\w+)\](?: \[(\d+)\])?:? ?(.*)$/s', $e->getMessage(), $m)) {
            [$state, $number, $message] = [$m[1], (int) $m[2], $m[3]];
        }

        return sprintf('ERROR %d (%s): %s', $number, $state, $message !== '' ? $message : $e->getMessage());
    }

    /**
     * The access of the person who runs: the client options (user, password, socket) of their option file, else their login
     * name and no password, through the local socket.
     *
     * @return array{host:string,user:string,pass:string,socket:?string}
     */
    public static function own(): array
    {
        $account = function_exists('posix_geteuid') ? (posix_getpwuid(posix_geteuid()) ?: []) : [];
        $options = self::options(($account['dir'] ?? (getenv('HOME') ?: '')) . '/.my.cnf');

        return [
            'host' => 'localhost',
            'user' => $options['user'] ?? ($account['name'] ?? (string) get_current_user()),
            'pass' => $options['password'] ?? '',
            'socket' => $options['socket'] ?? null,
        ];
    }

    /**
     * The options a MySQL client reads from a file, [client] then [mysql], the last value of a name winning.
     *
     * @return array<string,string>
     */
    public static function options(string $file): array
    {
        $options = [];
        $section = '';
        foreach (is_readable($file) ? file($file, FILE_IGNORE_NEW_LINES) ?: [] : [] as $line) {
            $line = trim($line);
            if ($line === '' || in_array($line[0], ['#', ';', '!'], true)) {
                continue;
            }
            if (preg_match('/^\[(.+)\]$/', $line, $m)) {
                $section = $m[1];

                continue;
            }
            if (!in_array($section, ['client', 'mysql'], true)) {
                continue;
            }
            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');
            $value = trim($value);
            $value = preg_match('/^(["\'])(.*?)\1/', $value, $q) ? stripcslashes($q[2]) : trim(preg_replace('/\s+#.*$/', '', $value));
            $options[str_replace('_', '-', trim($name))] = $value;
        }

        return $options;
    }

    /**
     * The statements as root.
     *
     * @param array<string,mixed> $access
     * @return array{0:int,1:string,2:string}
     */
    private static function viaSudo(array $access, string $sql, ?string $database): array
    {
        $script = 'require ' . var_export(__FILE__, true) . '; exit(OpenSim\\Provisioning\\SqlRunner::child());';
        $process = @proc_open(
            ['sudo', '-n', PHP_BINARY, '-d', 'display_errors=0', '-r', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            return [1, '', 'could not run sudo'];
        }
        fwrite($pipes[0], (string) json_encode(['access' => ['sudo' => false] + $access, 'sql' => $sql, 'database' => $database]));
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1]);
        $err = trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $answer = json_decode($out, true);

        return is_array($answer) && count($answer) === 3 ? $answer : [1, '', $err !== '' ? $err : 'sudo did not run the statements'];
    }

    /** The process started by sudo: reads what to run, writes the three parts of its answer as JSON. */
    public static function child(): int
    {
        $request = json_decode((string) stream_get_contents(STDIN), true);
        if (!is_array($request)) {
            return 1;
        }
        echo json_encode(self::run($request['access'] ?? [], (string) ($request['sql'] ?? ''), $request['database'] ?? null));

        return 0;
    }

    /** OSPDO, from the engine, when no bootstrap has loaded it. */
    private static function load(): void
    {
        class_exists(\OSPDO::class, false) || require_once dirname(__DIR__, 2) . '/class-database.php';
    }
}
