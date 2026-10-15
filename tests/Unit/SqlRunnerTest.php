<?php

declare(strict_types=1);

/**
 * The statements of the setup go through PDO: how rows and errors are reported, the options file of the person who runs, the
 * process started with sudo, and the database service on a server that cannot be reached.
 */

use OpenSim\Provisioning\DatabaseService;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\SqlRunner;
use OpenSim\Provisioning\UiInterface;

require_once dirname(__DIR__, 2) . '/class-database.php';

/** An interface that keeps what it is told and says no to every question. */
function sql_ui(): UiInterface
{
    return new class implements UiInterface {
        /** @var list<string> */
        public array $said = [];

        public function intro(string $title): void {}
        public function note(string $message): void { $this->said[] = $message; }
        public function entity(string $name): string { return $name; }
        public function warn(string $message): void { $this->said[] = $message; }
        public function error(string $message): void { $this->said[] = $message; }
        public function outro(string $message): void {}
        public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string { return $default ?? ''; }
        public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array { return $defaults; }
        public function text(string $label, string $default = '', ?\Closure $validate = null, ?string $hint = null): string { return $default; }
        public function secret(string $label, ?\Closure $validate = null, ?string $hint = null): string { return ''; }
        public function form(array $fields, string $title = ''): array { return []; }
        public function confirm(string $label, bool $default = true): bool { return false; }
        public function spin(\Closure $callback, string $message): mixed { return $callback(); }
    };
}

describe('the rows of a statement', function () {
    test('are one line each, the columns separated by tabs', function () {
        $pdo = OSPDO::open(['driver' => 'sqlite', 'name' => ':memory:']);
        $pdo->exec('CREATE TABLE t (id INTEGER, name TEXT, note TEXT)');
        $pdo->exec("INSERT INTO t VALUES (1, 'Ada', NULL), (2, 'tab\there', 'back\\slash')");

        expect(SqlRunner::rows($pdo, 'SELECT * FROM t ORDER BY id'))->toBe("1\tAda\tNULL\n2\ttab\\there\tback\\\\slash\n")
            ->and(SqlRunner::rows($pdo, 'SELECT * FROM t WHERE id = 3'))->toBe('')
            ->and(SqlRunner::rows($pdo, 'SELECT COUNT(*) FROM t'))->toBe("2\n");
    })->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

    test('are written like the client writes a value', function () {
        expect(SqlRunner::cell(null))->toBe('NULL')
            ->and(SqlRunner::cell(12))->toBe('12')
            ->and(SqlRunner::cell("a\nb"))->toBe('a\\nb');
    });
});

describe('an error', function () {
    test('is told as "ERROR <number> (<state>): <message>"', function () {
        $pdo = OSPDO::open(['driver' => 'sqlite', 'name' => ':memory:']);

        expect(fn() => SqlRunner::rows($pdo, 'SELECT * FROM nothing'))->toThrow(PDOException::class);
        try {
            SqlRunner::rows($pdo, 'SELECT * FROM nothing');
        } catch (PDOException $e) {
            expect(SqlRunner::describe($e))->toMatch('/^ERROR \d+ \(HY000\): .*nothing/');
        }
    })->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

    test('of a server that cannot be reached is told the same way', function () {
        [$code, $rows, $error] = SqlRunner::run(['host' => '127.0.0.1', 'port' => 1, 'user' => 'nobody', 'pass' => 'secret'], 'SELECT 1');

        expect($code)->toBe(1)->and($rows)->toBe('')->and($error)->toMatch('/^ERROR 2002 \(HY000\): /')
            ->and($error)->not->toContain('secret');
    })->skip(fn() => !extension_loaded('pdo_mysql'), 'pdo_mysql is missing');
});

describe('the connection of OSPDO', function () {
    test('has the DSN of its driver, the port apart from the host', function () {
        expect(OSPDO::dsn('mysql', 'db.example.org:3307', 'grid'))->toBe('mysql:host=db.example.org;port=3307;dbname=grid')
            ->and(OSPDO::dsn('mysql', 'localhost'))->toBe('mysql:host=localhost')
            ->and(OSPDO::dsn('mysql', '::1', 'grid'))->toBe('mysql:host=::1;dbname=grid')
            ->and(OSPDO::dsn('mysql', 'localhost', null, '/run/mysqld/mysqld.sock'))->toBe('mysql:unix_socket=/run/mysqld/mysqld.sock')
            ->and(OSPDO::dsn('sqlite', '', ':memory:'))->toBe('sqlite::memory:');
    });

    test('throws what the caller handles, with no database name needed', function () {
        $pdo = OSPDO::open(['driver' => 'sqlite', 'name' => ':memory:']);

        expect($pdo->connected)->toBeTrue()->and($pdo->db_name)->toBe(':memory:');
        expect(fn() => OSPDO::open(['driver' => 'sqlite', 'name' => '/nowhere/at/all.db']))->toThrow(PDOException::class);
    })->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
});

describe('the options file of the person who runs', function () {
    test('gives the user, the password and the socket of the client sections, the last one winning', function () {
        $file = test_tmp() . '/my-' . bin2hex(random_bytes(4)) . '.cnf';
        file_put_contents($file, <<<'CNF'
            # a comment
            [mysqld]
            user = mysql
            [client]
            user = alice
            password = "pa ss#word"
            socket=/run/mysqld/mysqld.sock
            [mysql]
            user=bob # the person
            CNF);

        $options = SqlRunner::options($file);
        unlink($file);

        expect($options)->toBe([
            'user' => 'bob',
            'password' => 'pa ss#word',
            'socket' => '/run/mysqld/mysqld.sock',
        ]);
    });

    test('is nothing when there is no file', function () {
        expect(SqlRunner::options('/nowhere/.my.cnf'))->toBe([]);
    });
});

describe('the process started with sudo', function () {
    test('reads what to run on its input and answers in JSON', function () {
        $script = 'require ' . var_export(dirname(__DIR__, 2) . '/src/Provisioning/SqlRunner.php', true)
            . '; exit(OpenSim\\Provisioning\\SqlRunner::child());';
        $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fwrite($pipes[0], json_encode(['access' => ['host' => '127.0.0.1', 'port' => 1, 'user' => 'x', 'pass' => 'y'], 'sql' => 'SELECT 1', 'database' => null]));
        fclose($pipes[0]);
        $answer = json_decode((string) stream_get_contents($pipes[1]), true);
        proc_close($process);

        expect($answer)->toBeArray()->and($answer[0])->toBe(1)->and($answer[2])->toStartWith('ERROR 2002');
    })->skip(fn() => !extension_loaded('pdo_mysql'), 'pdo_mysql is missing');
});

describe('the database service', function () {
    test('says it cannot reach a server that does not answer, and can be tried again', function () {
        $ui = sql_ui();
        $plan = new GridPlan();
        [$plan->dbHost, $plan->dbName, $plan->dbUser, $plan->dbPass] = ['127.0.0.1:1', 'grid_robust', 'grid', 'secret'];

        $result = (new DatabaseService($ui))->ensure($plan);

        expect($result)->toBe(DatabaseService::RETRY)
            ->and(implode("\n", $ui->said))->toContain('Cannot reach the database server 127.0.0.1:1')
            ->and(implode("\n", $ui->said))->not->toContain('secret');
    })->skip(fn() => !extension_loaded('pdo_mysql'), 'pdo_mysql is missing');

    test('gives no rows for a query that cannot be run', function () {
        $db = OpenSim\Config\ConnectionString::for('127.0.0.1:1', 'grid', 'grid', 'secret');

        expect((new DatabaseService(sql_ui()))->query($db, 'SELECT 1'))->toBeNull();
    });
});
