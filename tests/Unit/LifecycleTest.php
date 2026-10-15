<?php

declare(strict_types=1);

/**
 * Starting and stopping the instances of a machine: Robust, then the simulators, with processes, consoles and time that are
 * fakes of a machine that is a folder.
 */

use OpenSim\Models\Grid;
use OpenSim\OpenSim;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\Outcome;
use OpenSim\Runtime\RuntimeProbe;
use OpenSim\Testing\Machine;

/** The lines a Robust of the machine writes when it loads its two services. */
function robust_ready(Machine $machine): void
{
    $machine->startLog(
        'demo',
        '[SERVER]: AssetServiceConnector loaded successfully',
        '[SERVER]: GridServiceConnector loaded successfully',
    );
}

describe('Starting Robust', function () {
    test('launches the assembly of the core of its grid and waits for every service it lists', function () {
        $machine = new Machine();
        robust_ready($machine);

        $result = Grid::find($machine->environment, 'demo')->robust()->start();

        expect($result->outcome)->toBe(Outcome::Started)->and($machine->launcher->launched)->toHaveCount(1);
        $spec = $machine->launcher->launched[0];
        expect($spec->name)
            ->toBe('demo')
            ->and($spec->directory)
            ->toBe($machine->core . '/bin')
            ->and($spec->screen)
            ->toBeFalse()
            ->and($spec->command)
            ->toContain('dotnet', '--roll-forward', 'Major', $machine->core . '/bin/Robust.dll', '-console', 'rest')
            ->and($spec->command)
            ->toContain('-inifile=' . $machine->etc . '/robust.d/demo.ini')
            ->and($spec->command)
            ->toContain('-prompt', 'Robust (demo) ')
            ->and($machine->reporter->info)
            ->toContain('starting demo', 'demo started')
            ->and($machine->reporter->steps)
            ->toContain('  AssetServiceConnector 1/2', '  GridServiceConnector 2/2');
    });

    test('says which services failed to load', function () {
        $machine = new Machine();
        $machine->startLog(
            'demo',
            '[SERVER]: AssetServiceConnector loaded successfully',
            'ERROR Failed to load OpenSim.Server.Handlers.dll:GridServiceConnector',
        );

        $result = Grid::find($machine->environment, 'demo')->robust()->start();

        expect($result->outcome)
            ->toBe(Outcome::Failed)
            ->and($result->message)
            ->toBe('demo is up but these services failed to load: GridServiceConnector')
            ->and($machine->reporter->errors)
            ->toContain('  GridServiceConnector: failed to load (2/2)');
    });

    test('is not done for an instance that runs already', function () {
        $machine = new Machine();
        $machine->processes->add('dotnet Robust.dll -inifile=' . $machine->etc . '/robust.d/demo.ini');

        expect(Grid::find($machine->environment, 'demo')->robust()->start()->outcome)
            ->toBe(Outcome::Running)
            ->and($machine->launcher->launched)
            ->toBe([]);
    });

    test('fails when the process exits at once, with its output', function () {
        $machine = new Machine();
        $machine->launcher->exitsAtOnce = true;
        $machine->launcher->behavior = fn($spec) => file_put_contents($spec->output, "Unhandled exception: boom\n");

        $result = Grid::find($machine->environment, 'demo')->robust()->start();

        expect($result->outcome)
            ->toBe(Outcome::Failed)
            ->and($result->message)
            ->toBe('demo failed to start: the process exited immediately')
            ->and($machine->reporter->errors)
            ->toContain('Unhandled exception: boom');
    });

    test('fails with a clear message when the core is not found', function () {
        $machine = new Machine();
        unlink($machine->core . '/bin/Robust.dll');

        expect(Grid::find($machine->environment, 'demo')->robust()->start()->message)->toBe(
            'Executable Robust.dll not found',
        );
    });

    test('fails without launching when the runtime is not installed', function () {
        $machine = new Machine();
        $env = new Environment(
            $machine->environment->layout,
            $machine->processes,
            $machine->launcher,
            $machine->consoles,
            $machine->clock,
            $machine->reporter,
            null,
            new RuntimeProbe(static fn(string $command): string => ''),
        );

        $result = Grid::find($env, 'demo')->robust()->start();

        expect($result->ok())
            ->toBeFalse()
            ->and($result->message)
            ->toContain('dotnet is not installed')
            ->and($machine->launcher->launched)
            ->toBe([]);
    });
});

describe('Starting a simulator', function () {
    test('with a remote console runs detached and waits for its region to be ready', function () {
        $machine = new Machine();
        $machine->startLog(
            'alpha',
            '[REGION LOADER FILE SYSTEM]: Loaded config for region Plaza',
            '[RegionReady]: Region "Plaza" is ready',
        );

        $result = Grid::find($machine->environment, 'demo')->simulator('alpha')->start();

        expect($result->outcome)
            ->toBe(Outcome::Started)
            ->and($machine->launcher->launched[0]->screen)
            ->toBeFalse()
            ->and($machine->launcher->launched[0]->bigStack)
            ->toBeTrue()
            ->and($machine->launcher->launched[0]->command)
            ->toContain($machine->core . '/bin/OpenSim.dll', '-console', 'rest')
            ->and($machine->reporter->steps)
            ->toContain('  Plaza: configured (1/1)');
    });

    test('has no region, it is not started: it would wait for questions nobody reads', function () {
        $machine = new Machine();
        unlink($machine->grid . '/sims/alpha/regions/Plaza.ini');

        $result = Grid::find($machine->environment, 'demo')->simulator('alpha')->start();

        expect($result->ok())
            ->toBeFalse()
            ->and($result->message)
            ->toContain('alpha has no region to load')
            ->and($machine->launcher->launched)
            ->toBe([]);
    });

    test(
        'runs in a screen session when its config gives no remote console, and answers the questions of a first start',
        function () {
            $machine = new Machine();
            file_put_contents(
                $machine->grid . '/sims/alpha.ini',
                str_replace(
                    'console_port',
                    ';console_port',
                    (string) file_get_contents($machine->grid . '/sims/alpha.ini'),
                ),
            );
            $console = $machine->consoles->console('alpha');
            $console->screens = [
                'Region (Plaza) RegionUUID [11111111]:',
                "Region (Plaza) RegionUUID [11111111]:\nExternal host name [SYSTEMIP]:",
                "[RegionReady]: Region \"Plaza\" is ready",
            ];
            $machine->launcher->behavior = function () use ($machine) {
                $machine->consoles->sessions['alpha'] = true;
            };

            $result = Grid::find($machine->environment, 'demo')->simulator('alpha')->start();

            expect($machine->launcher->launched[0]->screen)
                ->toBeTrue()
                ->and($machine->launcher->launched[0]->command)
                ->not->toContain('rest')
                ->and($console->sent)
                ->toBe(['', ''])
                ->and($result->outcome)
                ->toBe(Outcome::Started);
        },
    );
});

describe('Stopping a simulator', function () {
    test('without a user in its regions asks the console to shut down and waits for the process', function () {
        $machine = new Machine();
        $process = $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');
        $machine->http['http://127.0.0.1:9000/monitorstats/11111111-1111-4111-8111-111111111111'] =
            '<AgentCountMonitor>0</AgentCountMonitor>';
        $machine->consoles->console('alpha')->onSend = function (string $line) use ($machine, $process) {
            $line === 'shutdown' && $machine->processes->remove($process->pid);
        };

        $result = Grid::find($machine->environment, 'demo')->simulator('alpha')->stop();

        expect($result->outcome)
            ->toBe(Outcome::Stopped)
            ->and($machine->consoles->console('alpha')->sent)
            ->toBe(['shutdown'])
            ->and($machine->reporter->info)
            ->toContain('alpha: no user in its regions, stopping now', 'stopping alpha', 'alpha stopped')
            ->and($machine->clock->total())
            ->toBeLessThan(5.0);
    });

    test('warns the users, counts down to the shutdown, then shuts down', function () {
        $machine = new Machine();
        $process = $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');
        $machine->http['http://127.0.0.1:9000/monitorstats/11111111-1111-4111-8111-111111111111'] =
            '<AgentCountMonitor>1</AgentCountMonitor>';
        $console = $machine->consoles->console('alpha');
        $console->answers['show users'] = [
            'Root agents in region Plaza: 1 (root 1, child 0)',
            'Ann              Lee              11111111-2222-3333-4444-555555555555  Root        <1, 2, 3>',
        ];
        $console->onSend = function (string $line) use ($machine, $process) {
            $line === 'shutdown' && $machine->processes->remove($process->pid);
        };

        $result = Grid::find($machine->environment, 'demo')->simulator('alpha')->stop();

        $alerts = array_values(
            array_filter($console->sent, static fn(string $l): bool => str_starts_with($l, 'alert')),
        );
        expect($result->outcome)
            ->toBe(Outcome::Stopped)
            ->and($alerts)
            ->toHaveCount(4)
            ->and($alerts[0])
            ->toStartWith('alert Attention: this simulator will be stopped in 120 seconds')
            ->and($alerts[1])
            ->toStartWith('alert Attention: this simulator will be stopped in 80 seconds')
            ->and($alerts[3])
            ->toBe('alert Shutting down now.')
            ->and(end($console->sent))
            ->toBe('shutdown')
            ->and($machine->clock->total())
            ->toBeGreaterThanOrEqual(120.0);
    });

    test('stop now does not warn, it only says it shuts down', function () {
        $machine = new Machine();
        $process = $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');
        $machine->consoles->console('alpha')->onSend = fn(string $l) => $l === 'shutdown' &&
            $machine->processes->remove($process->pid);

        Grid::find($machine->environment, 'demo')->simulator('alpha')->stop(true);

        expect($machine->consoles->console('alpha')->sent)->toBe(['alert Shutting down now.', 'shutdown']);
    });

    test('is forced when it does not stop in time', function () {
        $machine = new Machine();
        $machine->processes->add('dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini');
        $machine->processes->obeys = ['KILL'];

        $result = Grid::find($machine->environment, 'demo')->simulator('alpha')->stop(true);

        expect($result->outcome)
            ->toBe(Outcome::Stopped)
            ->and($machine->processes->signals)
            ->toBe(['TERM 4000', 'KILL 4000'])
            ->and($machine->reporter->info)
            ->toContain('alpha still running after 120s, forcing it', 'alpha stopped (forced)');
    });

    test('is a result of its own when it does not run', function () {
        $machine = new Machine();

        expect(Grid::find($machine->environment, 'demo')->simulator('alpha')->stop(true)->outcome)
            ->toBe(Outcome::NotRunning)
            ->and($machine->consoles->console('alpha')->sent)
            ->toBe([]);
    });
});

describe('Starting and stopping what a name means', function () {
    /** What a Robust and a region write in their log when they come up. */
    function demo_machine_ready(Machine $machine): void
    {
        $machine->launcher->behavior = function ($spec) use ($machine) {
            $lines = match ($spec->name) {
                'demo' => [
                    '[SERVER]: AssetServiceConnector loaded successfully',
                    '[SERVER]: GridServiceConnector loaded successfully',
                ],
                default => ['[RegionReady]: Region "Plaza" is ready'],
            };
            file_put_contents($machine->root . "/logs/{$spec->name}.log", implode("\n", $lines) . "\n", FILE_APPEND);
        };
    }

    test('a grid is its services: only its Robust starts', function () {
        $machine = new Machine();
        demo_machine_ready($machine);

        $results = OpenSim::start('demo', $machine->environment);

        expect(array_map(static fn($r): string => $r->instance . ':' . $r->outcome->value, $results))->toBe([
            'demo:started',
        ]);
    });

    test('a grid starts its Robust first, then its simulators, when they are asked', function () {
        $machine = new Machine();
        demo_machine_ready($machine);

        $results = OpenSim::start('demo', $machine->environment, simulators: true);

        expect(array_map(static fn($r): string => $r->instance . ':' . $r->outcome->value, $results))->toBe([
            'demo:started',
            'alpha:started',
            'beta:started',
        ]);
    });

    /** Every instance of the machine runs, and shuts down when its console is told so. */
    function demo_machine_running(Machine $machine): void
    {
        foreach (['robust.d/demo', 'opensim.d/alpha', 'opensim.d/beta'] as $link) {
            $machine->processes->add('dotnet X.dll -inifile=' . $machine->etc . "/$link.ini");
        }
        foreach (['demo', 'alpha', 'beta'] as $name) {
            $machine->consoles->console($name)->onSend = function (string $line) use ($machine, $name) {
                if ($line === 'shutdown') {
                    foreach ($machine->processes->all() as $p) {
                        str_contains($p->arguments, "/$name.ini") && $machine->processes->remove($p->pid);
                    }
                }
            };
        }
    }

    test('a grid stops its Robust alone, the simulators go on', function () {
        $machine = new Machine();
        demo_machine_running($machine);

        $results = OpenSim::stop('demo', true, $machine->environment);

        expect(array_map(static fn($r): string => $r->instance, $results))
            ->toBe(['demo'])
            ->and(count($machine->processes->all()))
            ->toBe(2);
    });

    test('a grid stops its simulators first, then its Robust, when they are asked', function () {
        $machine = new Machine();
        demo_machine_running($machine);

        $results = OpenSim::stop('demo', true, $machine->environment, simulators: true);

        expect(array_map(static fn($r): string => $r->instance, $results))->toBe(['alpha', 'beta', 'demo']);
    });

    test('a grid restarts its Robust alone, or with its simulators', function () {
        $machine = new Machine();
        demo_machine_running($machine);
        demo_machine_ready($machine);

        $alone = OpenSim::restart('demo', true, $machine->environment);
        expect(array_map(static fn($r): string => $r->instance, $alone))->toBe(['demo']);

        $all = OpenSim::restart('demo', true, $machine->environment, simulators: true);
        expect(array_map(static fn($r): string => $r->instance, $all))->toBe(['demo', 'alpha', 'beta']);
    });

    test('a config file names one instance', function () {
        $machine = new Machine();

        expect(OpenSim::instance($machine->environment, $machine->grid . '/Robust.HG.ini')?->type())
            ->toBe('robust')
            ->and(OpenSim::instance($machine->environment, $machine->grid . '/sims/alpha.ini')?->type())
            ->toBe('opensim');
    });

    test('a simulator is found by its name', function () {
        $machine = new Machine();

        expect(OpenSim::instance($machine->environment, 'beta')?->name())->toBe('beta');
    });

    test('a name that is nothing is a failed result, said', function () {
        $machine = new Machine();

        $results = OpenSim::start('nosuch', $machine->environment);

        expect($results[0]->ok())
            ->toBeFalse()
            ->and($machine->reporter->errors)
            ->toContain('no ini file found for nosuch');
    });

    test('no name is every enabled instance, the Robusts first', function () {
        $machine = new Machine();

        expect(array_map(static fn($i): string => $i->name(), OpenSim::enabled($machine->environment)))->toBe([
            'demo',
            'alpha',
            'beta',
        ]);
    });
});

describe('status', function () {
    test('lists the enabled instances, running or not, with what the processes use', function () {
        $machine = new Machine();
        $machine->processes->add(
            'dotnet OpenSim.dll -inifile=' . $machine->etc . '/opensim.d/alpha.ini',
            'opensim',
            1.5,
            2.5,
            100000,
        );

        $status = OpenSim::status($machine->environment);

        expect(
            array_map(
                static fn($s): string => $s->name() . ':' . ($s->isRunning() ? 'up' : 'down'),
                $status->instances,
            ),
        )
            ->toBe(['demo:down', 'alpha:up', 'beta:down'])
            ->and($status->up())
            ->toBe(1)
            ->and($status->down())
            ->toBe(2)
            ->and($status->totals())
            ->toBe(['cpu' => 1.5, 'memory' => 2.5, 'rss' => 100000]);
    });
});

describe('Enabling', function () {
    test('links the config where the launcher finds it, and unlinks it', function () {
        $machine = new Machine();
        $beta = Grid::find($machine->environment, 'demo')->simulator('beta');

        $beta->disable();
        expect($beta->isEnabled())->toBeFalse();
        expect(array_map(static fn($i): string => $i->name(), OpenSim::enabled($machine->environment)))->toBe([
            'demo',
            'alpha',
        ]);

        expect(Grid::find($machine->environment, 'demo')->simulator('beta')->enable())->toBeTrue();
        expect(readlink($machine->etc . '/opensim.d/beta.ini'))->toBe($machine->grid . '/sims/beta.ini');
    });
});
