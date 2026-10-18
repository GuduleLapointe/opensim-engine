<?php

declare(strict_types=1);

/**
 * The models of the machine (grids, simulators) are listed, found and described as the models of the databases are, in the
 * environment of the machine.
 */

use OpenSim\Data\Collection;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Interfaces\Enablable;
use OpenSim\Interfaces\InstalledModelInterface;
use OpenSim\Interfaces\ModelInterface;
use OpenSim\Interfaces\OpenSimModelInterface;
use OpenSim\Models\Avatar;
use OpenSim\Models\Estate;
use OpenSim\Models\Grid;
use OpenSim\Models\Parcel;
use OpenSim\Models\Region;
use OpenSim\Models\Simulator;
use OpenSim\Testing\Machine;

/** A machine with the grid demo, and another grid called second whose simulator is not enabled. */
function installed_machine(): Machine
{
    $machine = new Machine();
    $machine->write(
        $machine->etc . '/grids/second/Robust.ini',
        "[Const]\n    BaseHostname = \"second.example.org\"\n    PublicPort = 8102\n    PrivatePort = 8103\n[GridInfoService]\n    gridname = \"Second World\"\n",
    );
    $machine->write(
        $machine->etc . '/grids/second/sims/second_welcome.ini',
        "[Network]\n    http_listener_port = 9100\n",
    );

    return $machine;
}

describe('the contract', function () {
    test('is the one of every model, the models of the machine listing and finding in its environment', function () {
        foreach ([Grid::class, Simulator::class] as $class) {
            expect(is_subclass_of($class, InstalledModelInterface::class))
                ->toBeTrue("$class")
                ->and(is_subclass_of($class, ModelInterface::class))
                ->toBeTrue("$class")
                ->and(is_subclass_of($class, Enablable::class))
                ->toBeTrue("$class")
                ->and(is_subclass_of($class, OpenSimModelInterface::class))
                ->toBeFalse("$class");
        }
        foreach ([Avatar::class, Region::class, Parcel::class, Estate::class] as $class) {
            expect(is_subclass_of($class, OpenSimModelInterface::class))
                ->toBeTrue("$class")
                ->and(is_subclass_of($class, ModelInterface::class))
                ->toBeTrue("$class")
                ->and(is_subclass_of($class, InstalledModelInterface::class))
                ->toBeFalse("$class");
        }
    });

    test('has no owner, no flags, no date and a write refused, for what a config does not say', function () {
        $machine = installed_machine();
        $grid = Grid::find($machine->environment, 'demo');

        expect([$grid->uuid(), $grid->owner(), $grid->group(), $grid->flags(), $grid->lastSeen()])
            ->toBe([null, null, null, 0, null])
            ->and(fn() => $grid->update(['name' => 'x']))
            ->toThrow(ChannelUnavailable::class)
            ->and(fn() => $grid->delete())
            ->toThrow(ChannelUnavailable::class)
            ->and(fn() => Grid::create($machine->environment, []))
            ->toThrow(ChannelUnavailable::class, 'made by the setup');
    });
});

describe('grids', function () {
    test('are listed as a collection, searched, filtered, sorted and paged', function () {
        $machine = installed_machine();
        $grids = Grid::all($machine->environment);

        expect($grids)
            ->toBeInstanceOf(Collection::class)
            ->and($grids->total())
            ->toBe(2)
            ->and(array_map(static fn(Grid $g): string => $g->id(), $grids->items()))
            ->toBe(['demo', 'second'])
            ->and(
                array_map(
                    static fn(Grid $g): string => $g->id(),
                    Grid::all($machine->environment, ['search' => 'second'])->items(),
                ),
            )
            ->toBe(['second'])
            ->and(
                array_map(
                    static fn(Grid $g): string => $g->id(),
                    Grid::all($machine->environment, ['sort' => 'port desc'])->items(),
                ),
            )
            ->toBe(['second', 'demo'])
            ->and(
                array_map(
                    static fn(Grid $g): string => $g->id(),
                    Grid::all($machine->environment, ['filters' => ['enabled' => 'yes']])->items(),
                ),
            )
            ->toBe(['demo'])
            ->and(Grid::all($machine->environment, ['filters' => ['enabled' => 'no']])->applied())
            ->toBe(['enabled' => 'no'])
            ->and(count(Grid::all($machine->environment, ['perPage' => 1, 'page' => 2])->items()))
            ->toBe(1)
            ->and(Grid::all($machine->environment, ['perPage' => 1])->total())
            ->toBe(2);
    });

    test('are found by their nick, described by their address, and tell how they can be changed', function () {
        $machine = installed_machine();
        $second = Grid::find($machine->environment, 'second');

        expect($second->id())
            ->toBe('second')
            ->and($second->uri())
            ->toBe('second.example.org:8102')
            ->and($second->detail())
            ->toMatchArray(['nick' => 'second', 'name' => 'Second World', 'enabled' => false])
            ->and(json_encode($second->detail()))
            ->toBeString()
            ->and(Grid::find($machine->environment, 'nowhere'))
            ->toBeNull();
    });
});

describe('simulators', function () {
    test('are those of every grid, found by their address or their slug', function () {
        $machine = installed_machine();
        $all = Simulator::all($machine->environment);

        expect($all->total())
            ->toBe(3)
            ->and(array_map(static fn(Simulator $s): string => $s->slug(), $all->items()))
            ->toBe(['alpha', 'beta', 'second_welcome'])
            ->and(
                array_map(
                    static fn(Simulator $s): string => $s->slug(),
                    Simulator::all($machine->environment, ['filters' => ['grid' => 'second']])->items(),
                ),
            )
            ->toBe(['second_welcome'])
            ->and(
                array_map(
                    static fn(Simulator $s): string => $s->slug(),
                    Simulator::all($machine->environment, ['filters' => ['enabled' => 'no']])->items(),
                ),
            )
            ->toBe(['second_welcome'])
            ->and(
                array_map(
                    static fn(Simulator $s): string => $s->slug(),
                    Simulator::all($machine->environment, ['sort' => 'port desc'])->items(),
                ),
            )
            ->toBe(['second_welcome', 'beta', 'alpha'])
            ->and(Simulator::find($machine->environment, 'beta')?->httpPort())
            ->toBe(9010)
            ->and(Simulator::find($machine->environment, gethostname() . ':9100')?->slug())
            ->toBe('second_welcome')
            ->and(Simulator::find($machine->environment, 'nowhere'))
            ->toBeNull();
    });
});
