<?php

declare(strict_types=1);

/**
 * What the machine needs to run a core: the runtime, the modules, the processes.
 */

use OpenSim\Runtime\Core;
use OpenSim\Runtime\ModuleLinker;
use OpenSim\Runtime\Process;
use OpenSim\Runtime\RuntimeProbe;
use OpenSim\Runtime\SystemProcessTable;

/** A core that asks for .NET 8. */
function probe_core(string $name = '0.9.3.0'): Core
{
    $root = sys_get_temp_dir() . '/probe-' . bin2hex(random_bytes(4));
    mkdir("$root/$name/bin", 0o755, true);
    touch("$root/$name/bin/OpenSim.dll");
    file_put_contents(
        "$root/$name/bin/OpenSim.runtimeconfig.json",
        '{"framework":{"name":"Microsoft.NETCore.App","version":"8.0.0"}}',
    );

    return Core::at("$root/$name");
}

describe('runtime of a core', function () {
    test('is there when a .NET of the major it asks for, or a newer one, is installed', function () {
        $probe = new RuntimeProbe(
            static fn(string $c): string => str_contains($c, '--list-runtimes')
                ? "Microsoft.NETCore.App 9.0.1 [/x]\n"
                : '/usr/bin/dotnet',
        );

        expect($probe->problem(probe_core(), 'OpenSim.dll'))->toBeNull();
    });

    test('is missing when dotnet is not installed, with the command that installs it', function () {
        $probe = new RuntimeProbe(static fn(string $c): string => '');

        expect($probe->problem(probe_core(), 'OpenSim.dll'))->toBe(
            'dotnet is not installed. Install the .NET runtime with: sudo opensim install-dotnet 8',
        );
    });

    test('is missing when the .NET installed is older than the one it asks for', function () {
        $probe = new RuntimeProbe(
            static fn(string $c): string => str_contains($c, '--list-runtimes')
                ? "Microsoft.NETCore.App 6.0.1 [/x]\n"
                : '/usr/bin/dotnet',
        );

        expect($probe->problem(probe_core(), 'OpenSim.dll'))
            ->toContain('needs Microsoft.NETCore.App 8 or newer')
            ->toContain('installed: 6');
    });

    test('is Mono for a release before 0.9.3.0', function () {
        $root = sys_get_temp_dir() . '/probe-' . bin2hex(random_bytes(4));
        mkdir("$root/0.9.2.2/bin", 0o755, true);
        touch("$root/0.9.2.2/bin/OpenSim.exe");

        expect(
            (new RuntimeProbe(static fn(string $c): string => ''))->problem(Core::at("$root/0.9.2.2"), 'OpenSim.exe'),
        )
            ->toContain('mono is not installed')
            ->and(
                (new RuntimeProbe(static fn(string $c): string => '/usr/bin/mono'))->problem(
                    Core::at("$root/0.9.2.2"),
                    'OpenSim.exe',
                ),
            )
            ->toBeNull();
    });
});

describe('native libraries', function () {
    test('are found in the usual folders, a link that leads nowhere is not one', function () {
        $dir = sys_get_temp_dir() . '/libs-' . bin2hex(random_bytes(4));
        mkdir($dir);
        touch("$dir/libgdiplus.so.0.0.0");
        symlink("$dir/libgdiplus.so.0.0.0", "$dir/libgdiplus.so.0");
        $probe = new RuntimeProbe(static fn(string $c): string => '', [$dir]);

        expect($probe->hasLibrary('gdiplus'))->toBeTrue();

        unlink("$dir/libgdiplus.so.0.0.0");

        expect($probe->hasLibrary('gdiplus'))->toBeFalse();
    });

    test('are known to the loader', function () {
        $probe = new RuntimeProbe(
            static fn(string $c): string => "libgdiplus.so.0 (libc6,x86-64) => /usr/lib/libgdiplus.so.0\n",
            [],
        );

        expect($probe->hasLibrary('gdiplus'))->toBeTrue();
    });
});

describe('modules of an install', function () {
    /** A folder of modules: the files of gloebit and opensimsearch for the core 0.9.3.0. */
    function modules_root(): string
    {
        $root = sys_get_temp_dir() . '/modules-' . bin2hex(random_bytes(4));
        foreach (['gloebit' => 'Gloebit.dll', 'opensimsearch' => 'OpenSimSearch.Modules.dll'] as $module => $file) {
            mkdir("$root/0.9.3.0/$module", 0o755, true);
            touch("$root/0.9.3.0/$module/$file");
        }

        return $root;
    }

    test('are linked in the addins of the registry of the instance', function () {
        $root = modules_root();
        $addins = sys_get_temp_dir() . '/addins-' . bin2hex(random_bytes(4));

        ModuleLinker::sync($addins, probe_core('0.9.3.0'), ['gloebit', 'opensimsearch', 'absent'], $root);

        expect(readlink("$addins/Gloebit.dll"))
            ->toBe("$root/0.9.3.0/gloebit/Gloebit.dll")
            ->and(is_link("$addins/OpenSimSearch.Modules.dll"))
            ->toBeTrue();
    });

    test('that are not enabled any more are unlinked, the other files of the folder are not touched', function () {
        $root = modules_root();
        $addins = sys_get_temp_dir() . '/addins-' . bin2hex(random_bytes(4));
        ModuleLinker::sync($addins, probe_core('0.9.3.0'), ['gloebit', 'opensimsearch'], $root);
        touch("$addins/Mine.dll");

        ModuleLinker::sync($addins, probe_core('0.9.3.0'), ['gloebit'], $root);

        expect(is_link("$addins/OpenSimSearch.Modules.dll"))
            ->toBeFalse()
            ->and(is_link("$addins/Gloebit.dll"))
            ->toBeTrue()
            ->and(is_file("$addins/Mine.dll"))
            ->toBeTrue();
    });
});

describe('processes', function () {
    test('are the instances found in the output of ps', function () {
        $out = <<<'PS'
          101 root      0.0  0.1  1200 /sbin/init
         4321 opensim   2.5  3.0 123456 dotnet --roll-forward Major /usr/share/opensim/0.9.3.0/bin/Robust.dll -inifile=/etc/opensim/robust.d/demo.ini -logfile=/var/log/opensim/demo.log -prompt Robust (demo)  -logconfig=/x/demo.LogConfig
         4400 opensim  10,5  4.2 234567 mono /usr/share/opensim/0.9.2.2/bin/OpenSim.exe -inifile=/etc/opensim/opensim.d/alpha.ini
         4500 opensim   0.0  0.0   500 mono /usr/lib/mono/mcs.exe -inifile=/x
         4600 opensim   0.0  0.0   500 dotnet build
        PS;

        $found = SystemProcessTable::parse($out);

        expect(array_map(static fn(Process $p): int => $p->pid, $found))
            ->toBe([4321, 4400])
            ->and($found[0]->ini())
            ->toBe('/etc/opensim/robust.d/demo.ini')
            ->and($found[0]->user)
            ->toBe('opensim')
            ->and($found[0]->rss)
            ->toBe(123456)
            ->and($found[1]->cpu)
            ->toBe(10.5)
            ->and($found[0]->runs('/etc/opensim/robust.d/demo.ini'))
            ->toBeTrue()
            ->and($found[0]->runs('/etc/opensim/robust.d/dem'))
            ->toBeFalse();
    });

    test('are listed without cutting their command line, whatever width the environment says', function () {
        expect(SystemProcessTable::command())->toContain(' axww ')->toContain('args=');
    });

    test('write the log their arguments tell', function () {
        $dir = sys_get_temp_dir() . '/log-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents(
            "$dir/alpha.LogConfig",
            '<appender name="LogFileAppender" type="log4net.Appender.FileAppender"><file value="/var/log/opensim/alpha.log" /></appender>',
        );

        expect((new Process(1, 'u', 0, 0, 0, 'dotnet R.dll -inifile=/x.ini -logfile=/var/log/robust.log'))->logFile())
            ->toBe('/var/log/robust.log')
            ->and(
                (new Process(
                    1,
                    'u',
                    0,
                    0,
                    0,
                    "mono OpenSim.exe -inifile=/x.ini -logconfig=$dir/alpha.LogConfig",
                ))->logFile(),
            )
            ->toBe('/var/log/opensim/alpha.log');
    });
});
