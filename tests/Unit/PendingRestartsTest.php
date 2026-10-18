<?php
/**
 * What waits for an instance to restart.
 */

use OpenSim\Provisioning\PendingRestarts;
use OpenSim\Testing\EnvironmentFake;

describe('Pending restarts', function () {
    test('are kept one line each, once, and read back', function () {
        $root = test_tmp() . '/pending-' . bin2hex(random_bytes(4));
        mkdir("$root/data", 0o755, true);
        $environment = EnvironmentFake::with(['DataRoot' => "$root/data"]);
        PendingRestarts::add($environment, 'vonda_welcome', 'region Welcome added');
        PendingRestarts::add($environment, 'vonda_welcome', 'region  Welcome added');
        PendingRestarts::add($environment, 'vonda', 'grid config changed');

        expect(file_get_contents(PendingRestarts::path($environment)))->toBe(
            "vonda_welcome region Welcome added\nvonda grid config changed\n",
        );
        expect(PendingRestarts::read($environment))->toBe([
            ['instance' => 'vonda_welcome', 'reason' => 'region Welcome added'],
            ['instance' => 'vonda', 'reason' => 'grid config changed'],
        ]);

        PendingRestarts::clear($environment);
        expect(PendingRestarts::read($environment))->toBe([]);
    });

    test('are nothing for an install without a data folder', function () {
        $environment = EnvironmentFake::with();
        PendingRestarts::add($environment, 'a', 'b');

        expect(PendingRestarts::read($environment))->toBe([]);
    });
});
