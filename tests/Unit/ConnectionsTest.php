<?php

declare(strict_types=1);

/**
 * Whether credentials connect, and why not.
 */

use OpenSim\Config\ConnectionString;
use OpenSim\Data\Connections;

describe('credentials', function () {
    test('connect, or say what is missing or what the server answers', function () {
        expect(Connections::test(['driver' => 'sqlite', 'name' => ':memory:']))
            ->toBeNull()
            ->and(Connections::test(['host' => 'localhost', 'user' => '', 'name' => '']))
            ->toBe('missing: name, user')
            ->and(Connections::test([]))
            ->toBe('missing: host, name, user')
            ->and(
                Connections::test([
                    'host' => '127.0.0.1',
                    'port' => 1,
                    'user' => 'nobody',
                    'name' => 'nothing',
                    'pass' => 'x',
                ]),
            )
            ->toStartWith('ERROR ')
            ->and(
                Connections::test(
                    ConnectionString::parse('Data Source=127.0.0.1:1;Database=nothing;User ID=nobody;Password=x;'),
                ),
            )
            ->toStartWith('ERROR ');
    })->skip(
        fn() => !extension_loaded('pdo_sqlite') || !extension_loaded('pdo_mysql'),
        'pdo_sqlite or pdo_mysql is missing',
    );
});
