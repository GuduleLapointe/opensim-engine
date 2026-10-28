<?php

declare(strict_types=1);

use OpenSim\Config\Layout;
use OpenSim\Provisioning\NullUi;
use OpenSim\Provisioning\SystemLayout;

function system_layout_roots(string $base): Layout
{
    return new Layout([
        'EtcRoot' => "$base/etc/opensim",
        'VarRoot' => "$base/var/lib/opensim",
        'DataRoot' => "$base/var/lib/opensim/data",
        'LogsRoot' => "$base/var/log/opensim",
        'CacheRoot' => "$base/var/cache/opensim",
        'CoreRoot' => "$base/core",
    ]);
}

describe('The system layout', function () {
    test('is needed when the place of the grids cannot be written', function () {
        $base = sys_get_temp_dir() . '/syslayout-' . getmypid();
        mkdir($base, 0o555);
        $needed = SystemLayout::needed(system_layout_roots($base));
        chmod($base, 0o755);
        rmdir($base);

        // A folder that cannot be written (unless root) needs the layout; one that can does not
        expect($needed)->toBe(function_exists('posix_geteuid') && posix_geteuid() === 0 ? false : true)
            ->and(SystemLayout::needed(new Layout(['EtcRoot' => sys_get_temp_dir() . '/zz-new/etc'])))->toBeFalse();
    });

    test('makes the account, the folders and opensim.conf', function () {
        $base = sys_get_temp_dir() . '/syslayout-ok-' . getmypid();
        $commands = [];
        $ui = new NullUi();
        $made = (new SystemLayout($ui, function (string $c) use (&$commands): int {
            $commands[] = $c;

            return 0;
        }, fn() => false, true))->prepare(system_layout_roots($base));

        expect($made)->toBeTrue()
            ->and(is_dir("$base/etc/opensim/grids"))->toBeTrue()
            ->and(is_dir("$base/etc/opensim/robust.d"))->toBeTrue()
            ->and(is_dir("$base/var/log/opensim"))->toBeTrue()
            ->and(file_get_contents("$base/etc/opensim/opensim.conf"))->toContain('SystemUser = opensim', "EtcRoot = $base/etc/opensim")
            ->and(implode("\n", $commands))->toContain('opensim')
            ->and(implode("\n", $commands))->toMatch('/adduser|useradd/');
        exec('rm -rf ' . escapeshellarg($base));
    });

    test('keeps an opensim.conf that is there, and does not make an account that exists', function () {
        $base = sys_get_temp_dir() . '/syslayout-keep-' . getmypid();
        mkdir("$base/etc/opensim", 0o755, true);
        file_put_contents("$base/etc/opensim/opensim.conf", "[Defaults]\nSystemUser = mine\n");
        $commands = [];
        $made = (new SystemLayout(new NullUi(), function (string $c) use (&$commands): int {
            $commands[] = $c;

            return 0;
        }, fn() => true, true))->prepare(system_layout_roots($base));

        expect($made)->toBeTrue()
            ->and(file_get_contents("$base/etc/opensim/opensim.conf"))->toContain('SystemUser = mine')
            ->and(implode("\n", $commands))->not->toMatch('/adduser|useradd/');
        exec('rm -rf ' . escapeshellarg($base));
    });

    test('does not modify membership when the system user is already in the web group', function () {
        $base = sys_get_temp_dir() . '/syslayout-group-' . getmypid();
        $commands = [];
        $made = (new SystemLayout(
            new NullUi(),
            function (string $command) use (&$commands): int {
                $commands[] = $command;

                return 0;
            },
            fn() => true,
            true,
            fn(string $name): array|false => $name === 'www-data' ? ['members' => ['opensim']] : false,
        ))->prepare(system_layout_roots($base));

        expect($made)->toBeTrue()
            ->and(implode("\n", $commands))->not->toContain('/usr/sbin/usermod');
        exec('rm -rf ' . escapeshellarg($base));
    });

    test('refuses when it is not root', function () {
        $ui = new NullUi();

        expect((new SystemLayout($ui, null, null, false))->prepare(system_layout_roots('/nonexistent-zz')))->toBeFalse()
            ->and($ui->said[0][0])->toBe('error');
    });
});
