<?php

declare(strict_types=1);

/**
 * The ports an instance listens on, from its config and the descriptions of its regions.
 */

use OpenSim\Network\InstancePorts;

/** A config file in a temporary folder. */
function instance_ports_file(string $name, string $text): string
{
    $dir = sys_get_temp_dir() . '/instance-ports-' . bin2hex(random_bytes(4));
    mkdir($dir . '/regions', 0o755, true);
    file_put_contents("$dir/$name", $text);

    return "$dir/$name";
}

describe('The ports of an instance', function () {
    test('are, for Robust, its public and private ports, its additional services, its console', function () {
        $ini = instance_ports_file('Robust.HG.ini', <<<'INI'
            [Const]
                PublicPort = 8002
                PrivatePort = 8003
            [ServiceList]
                MapAddServiceConnector = 8010/OpenSim.Server.Handlers.dll:MapAddServiceConnector
                GridService = ${Const|PrivatePort}/OpenSim.Server.Handlers.dll:GridServiceConnector
            [Network]
                ConsolePort = 8404
                ConsoleUser = u
                ConsolePass = p
            INI);

        expect(array_map(static fn(array $p): string => "{$p['port']}/{$p['proto']} {$p['use']}", InstancePorts::of($ini)))
            ->toBe(['8002/tcp public', '8003/tcp private', '8010/tcp private', '8404/tcp console']);
    });

    test('are, for a simulator, its HTTP port, its console, and the UDP port of each of its regions', function () {
        $ini = instance_ports_file('sim.ini', "[Network]\n  http_listener_port = 9020\n  console_port = 9024\n  ConsoleUser = u\n  ConsolePass = p\n[Startup]\n  regionload_regionsdir = \"%DIR%/regions\"\n");
        $dir = dirname($ini);
        file_put_contents($ini, str_replace('%DIR%', $dir, (string) file_get_contents($ini)));
        file_put_contents("$dir/regions/Plaza.ini", "[Plaza]\r\nInternalPort = 9021\r\n[Park]\r\nInternalPort = 9022\r\n");

        expect(array_map(static fn(array $p): string => "{$p['port']}/{$p['proto']} {$p['use']}", InstancePorts::of($ini)))
            ->toBe(['9020/tcp public', '9021/udp public', '9022/udp public', '9024/tcp console']);
    });

    test('keep the port of a console that is not enabled, marked off', function () {
        $ini = instance_ports_file('sim.ini', "[Network]\n  http_listener_port = 9020\n  ;console_port = 9024\n");

        expect(array_map(static fn(array $p): string => "{$p['port']} {$p['use']}", InstancePorts::of($ini)))
            ->toBe(['9020 public', '9024 off']);
    });

    test('are read the way the caller reads files it cannot read itself', function () {
        $ini = instance_ports_file('sim.ini', '');
        $read = static fn(string $file): string => "[Network]\n  http_listener_port = 9100\n";

        expect(InstancePorts::of($ini, $read))->toBe([['port' => 9100, 'proto' => 'tcp', 'role' => 'HTTP of the simulator: viewers, grid, neighbours', 'use' => 'public']])
            ->and(InstancePorts::of('/nowhere/sim.ini'))->toBe([]);
    });
});
