<?php

declare(strict_types=1);

/**
 * The dump of a database: how a value is written in an INSERT, how a failure is told.
 */

use OpenSim\Data\OSPDO;
use OpenSim\Archives\DatabaseDump;

describe('a value in an INSERT', function () {
    test('is NULL, a number as it is, text quoted, what is not text in hexadecimal', function () {
        $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);

        expect(DatabaseDump::literal($pdo, null))
            ->toBe('NULL')
            ->and(DatabaseDump::literal($pdo, 42))
            ->toBe('42')
            ->and(DatabaseDump::literal($pdo, 0.1))
            ->toBe('0.1')
            ->and(DatabaseDump::literal($pdo, "it's"))
            ->toBe("'it''s'")
            ->and(DatabaseDump::literal($pdo, ''))
            ->toBe("''")
            ->and(DatabaseDump::literal($pdo, 'é'))
            ->toBe("'é'")
            ->and(DatabaseDump::literal($pdo, "\xff\x00\x01"))
            ->toBe('0x' . 'ff0001')
            ->and(DatabaseDump::literal($pdo, "a\0b"))
            ->toBe('0x' . '610062');
    })->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

    test('comes in an INSERT with the rows one per line', function () {
        expect(DatabaseDump::insert('`t`', ['(1,NULL)', "(2,'x')"]))->toBe(
            "INSERT INTO `t` VALUES (1,NULL),\n(2,'x');\n",
        );
    });

    test('has its table name quoted, a backtick doubled', function () {
        expect(DatabaseDump::identifier('UserAccounts'))
            ->toBe('`UserAccounts`')
            ->and(DatabaseDump::identifier('we`ird'))
            ->toBe('`we``ird`');
    });
});

describe('dump of a database that cannot be read', function () {
    test('fails with the reason and writes nothing worth keeping', function () {
        $file = test_tmp() . '/dump-' . bin2hex(random_bytes(4)) . '.sql.gz';

        expect(
            fn() => DatabaseDump::write(
                ['host' => '127.0.0.1:1', 'name' => 'grid', 'user' => 'x', 'pass' => 'secret'],
                $file,
            ),
        )->toThrow(RuntimeException::class, 'cannot connect to grid: ERROR 2002');
        @unlink($file);
    })->skip(fn() => !extension_loaded('pdo_mysql'), 'pdo_mysql is missing');
});
