<?php

declare(strict_types=1);

/**
 * The real users in the regions of a simulator, to know whether anybody is to be warned before it stops.
 */

use OpenSim\Models\Grid;
use OpenSim\Runtime\UserCounter;
use OpenSim\Testing\Machine;

const SHOW_USERS = [
    '2026-10-03 10:00:00,000 INFO  [SCENE]: Removing root agent 11111111-2222-3333-4444-555555555999 from Sim1',
    '',
    'Root agents in region Sim1: 3 (root 3, child 1)',
    'Firstname        Lastname         Agent ID                              Type        Position',
    'Ann              Lee              11111111-2222-3333-4444-555555555555  Root        <1, 2, 3>',
    'Bob              NPC              11111111-2222-3333-4444-555555555556  NPC Root    <1, 2, 3>',
    'Cy               Child            11111111-2222-3333-4444-555555555557  Child       <1, 2, 3>',
    '',
    'Root agents in region Sim2: 1 (root 1, child 0)',
    'Dee              Roe              11111111-2222-3333-4444-555555555558  Root        <1, 2, 3>',
];

/** The simulator `alpha` of a machine whose regions answer on their HTTP port as told: UUID => agents. */
function counted_simulator(Machine $machine, array $agents = []): OpenSim\Controllers\OpenSimController
{
    foreach ($agents as $uuid => $count) {
        $machine->http["http://127.0.0.1:9000/monitorstats/$uuid"] =
            $count === null ? null : "<data><AgentCountMonitor>$count</AgentCountMonitor></data>";
    }

    return Grid::find($machine->environment, 'demo')->simulator('alpha')->controller();
}

const ALPHA_REGION = '11111111-1111-4111-8111-111111111111';

describe('users read in show users', function () {
    test('are the roots, the NPCs and the child agents are not counted', function () {
        expect(UserCounter::realUsers(SHOW_USERS))->toBe(2);
    });

    test('are none for empty regions', function () {
        expect(UserCounter::realUsers(['', 'Root agents in region Sim1: 0 (root 0, child 0)']))->toBe(0);
    });

    test('are not guessed without a list', function () {
        expect(UserCounter::realUsers(['something else']))->toBeNull();
    });

    test('are read in the rows a screen cut at 80 columns', function () {
        // Only an NPC is there (the root count includes it), the type is in the 80 columns
        expect(
            UserCounter::realUsers([
                'Root agents in region Sim1: 1 (root 1, child 0)',
                'Bob              NPC              11111111-2222-3333-4444-555555555556  NPC Roo',
                't    <1, 2, 3>',
            ]),
        )
            ->toBe(0)
            // A real user whose type was cut off counts
            ->and(
                UserCounter::realUsers([
                    'Root agents in region Sim1: 2 (root 2, child 0)',
                    'Ann              Lee              11111111-2222-3333-4444-555555555555',
                    'Bob              NPC              11111111-2222-3333-4444-555555555556  NPC Roo',
                ]),
            )
            ->toBe(1)
            // Root agents without the rows to tell who they are: no answer
            ->and(UserCounter::realUsers(['Root agents in region Sim1: 1 (root 1, child 0)', 'Ann Lee 1111-truncated']))
            ->toBeNull();
    });
});

describe('users of a simulator', function () {
    test('are none when its HTTP port says none, the console is not asked', function () {
        $machine = new Machine();
        $simulator = counted_simulator($machine, [ALPHA_REGION => 0]);

        expect((new UserCounter($machine->environment))->count($simulator))
            ->toBe(0)
            ->and($machine->consoles->console('alpha')->sent)
            ->toBe([]);
    });

    test('are asked to the console when its HTTP port says some: it tells the users from the NPCs', function () {
        $machine = new Machine();
        $simulator = counted_simulator($machine, [ALPHA_REGION => 1]);
        $machine->consoles->console('alpha')->answers['show users'] = [
            'Root agents in region Plaza: 1 (root 1, child 0)',
            'Bob              NPC              11111111-2222-3333-4444-555555555556  NPC Root',
        ];

        expect((new UserCounter($machine->environment))->count($simulator))
            ->toBe(0)
            ->and($machine->consoles->console('alpha')->sent)
            ->toBe(['change region root', 'show users']);
    });

    test('are the agents it counts when the console does not answer', function () {
        $machine = new Machine();
        $simulator = counted_simulator($machine, [ALPHA_REGION => 2]);
        $machine->consoles->console('alpha')->available = false;

        expect((new UserCounter($machine->environment))->count($simulator))->toBe(2);
    });

    test('are not known when nothing answers, and nobody is waited for', function () {
        $machine = new Machine();
        $simulator = counted_simulator($machine, [ALPHA_REGION => null]);
        $machine->consoles->console('alpha')->available = false;
        $counter = new UserCounter($machine->environment);

        expect($counter->count($simulator))
            ->toBeNull()
            ->and($counter->nobodyToWaitFor($simulator))
            ->toBe(['nobody' => true, 'unknown' => true]);
    });

    test('are waited for when there are some', function () {
        $machine = new Machine();
        $simulator = counted_simulator($machine, [ALPHA_REGION => 2]);
        $machine->consoles->console('alpha')->available = false;

        expect((new UserCounter($machine->environment))->nobodyToWaitFor($simulator))->toBe([
            'nobody' => false,
            'unknown' => false,
        ]);
    });

    test('add the agents of every region', function () {
        $machine = new Machine();
        file_put_contents(
            $machine->grid . '/sims/alpha/regions/Park.ini',
            "[Park]\nRegionUUID = \"22222222-2222-4222-8222-222222222222\"\n",
        );
        $simulator = counted_simulator($machine, [ALPHA_REGION => 1, '22222222-2222-4222-8222-222222222222' => 2]);
        $machine->consoles->console('alpha')->available = false;

        expect((new UserCounter($machine->environment))->count($simulator))->toBe(3);
    });

    test('are not known when a region does not answer', function () {
        $machine = new Machine();
        file_put_contents(
            $machine->grid . '/sims/alpha/regions/Park.ini',
            "[Park]\nRegionUUID = 22222222-2222-4222-8222-222222222222\n",
        );
        $simulator = counted_simulator($machine, [ALPHA_REGION => 0]);
        $machine->consoles->console('alpha')->available = false;

        expect((new UserCounter($machine->environment))->count($simulator))->toBeNull();
    });

    test('are not known for a simulator without region files', function () {
        $machine = new Machine();
        unlink($machine->grid . '/sims/alpha/regions/Plaza.ini');
        $simulator = counted_simulator($machine);
        $machine->consoles->console('alpha')->available = false;

        expect((new UserCounter($machine->environment))->count($simulator))->toBeNull();
    });
});
