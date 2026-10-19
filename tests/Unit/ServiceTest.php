<?php

declare(strict_types=1);

/**
 * The services of a grid: served by its Robust, or declared for its simulators; a simulator answers for them as it reaches them.
 */

use OpenSim\Data\Database;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Grid;
use OpenSim\Models\Service;
use OpenSim\Models\Simulator;
use OpenSim\Testing\Machine;

/** The grid `demo` of the test machine; its Robust serves the assets and the regions, on its private port. */
function service_grid(Machine $machine): Grid
{
    return Grid::find($machine->environment, 'demo');
}

describe('services of a grid', function () {
    test(
        'are the ones its Robust serves, with the Robust that serves them and the address they are reached at',
        function () {
            $machine = new Machine();
            $grid = service_grid($machine);

            expect($grid->services()->items())
                ->toHaveCount(2)
                ->and(array_map(static fn(Service $service): string => $service->key(), $grid->services()->items()))
                ->toBe(['asset', 'grid'])
                ->and($grid->service('asset')->provider()->name())
                ->toBe('demo')
                ->and($grid->service('asset')->isServed())
                ->toBeTrue()
                ->and($grid->service('asset')->isExternal())
                ->toBeFalse()
                ->and($grid->service('asset')->uri())
                ->toBe('http://localhost:8003')
                ->and($grid->service('asset')->database())
                ->toBe(Database::Assets)
                ->and($grid->service('asset')->id())
                ->toBe('demo_asset')
                ->and($grid->service('profile'))
                ->toBeNull()
                ->and($grid->service('nonsense'))
                ->toBeNull();
        },
    );

    test('have a detail with the standard names', function () {
        $detail = service_grid(new Machine())->service('grid')->detail();

        expect($detail)->toMatchArray([
            'id' => 'demo_grid',
            'key' => 'grid',
            'grid' => 'demo',
            'served' => true,
            'external' => false,
            'port' => 'private',
            'section' => 'GridService',
            'setting' => 'GridServerURI',
            'database' => 'robust',
        ]);
    });

    test('are found by their slug among the services of the machine, and listed with filters', function () {
        $machine = new Machine();

        expect(Service::find($machine->environment, 'demo_asset')?->key())
            ->toBe('asset')
            ->and(Service::find($machine->environment, 'demo_profile'))
            ->toBeNull()
            ->and(Service::all($machine->environment)->items())
            ->toHaveCount(2)
            ->and(Service::all($machine->environment, ['filters' => ['port' => 'public']])->items())
            ->toBe([])
            ->and(Service::all($machine->environment, ['search' => 'regions'])->items())
            ->toHaveCount(1);
    });

    test('are not enabled or disabled by themselves', function () {
        $service = service_grid(new Machine())->service('asset');

        expect(fn() => $service->enable())
            ->toThrow(ChannelUnavailable::class)
            ->and(fn() => $service->disable())
            ->toThrow(ChannelUnavailable::class);
    });
});

describe('services as a simulator reaches them', function () {
    test('are its grid\'s, each at the address of its grid', function () {
        $machine = new Machine();
        $beta = service_grid($machine)->simulator('beta');

        expect($beta)
            ->toBeInstanceOf(Simulator::class)
            ->and($beta->service('asset')->uri())
            ->toBe('http://localhost:8003')
            ->and($beta->service('asset')->viewer()?->id())
            ->toBe($beta->id())
            ->and($beta->services()->items())
            ->toHaveCount(2);
    });

    test(
        'are the address its own config says when it says one, even for a service its grid does not have',
        function () {
            $machine = new Machine();
            $machine->write(
                $machine->grid . '/sims/alpha.ini',
                file_get_contents($machine->grid . '/sims/alpha.ini') .
                    "\n[AssetService]\n    AssetServerURI = \"http://assets.example.org:8003\"\n[UserProfiles]\n    ProfileServiceURL = \"https://profiles.example.org\"\n",
            );
            $grid = service_grid($machine);
            $alpha = $grid->simulator('alpha');
            $beta = $grid->simulator('beta');

            expect($alpha->service('asset')->uri())
                ->toBe('http://assets.example.org:8003')
                ->and($alpha->service('asset')->isServed())
                ->toBeTrue()
                ->and($alpha->service('profile')->uri())
                ->toBe('https://profiles.example.org')
                ->and($alpha->service('profile')->isServed())
                ->toBeFalse()
                ->and($alpha->service('profile')->isExternal())
                ->toBeTrue()
                ->and($beta->service('profile'))
                ->toBeNull()
                ->and($beta->service('asset')->uri())
                ->toBe('http://localhost:8003');
        },
    );
});

describe('web services of a grid', function () {
    /** The grid `demo` with the addresses its config gives to the web services. */
    function web_grid(): Grid
    {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/Robust.HG.ini',
            file_get_contents($machine->grid . '/Robust.HG.ini') .
                "\n[GridInfoService]\n    economy = \"https://play.example.org/helpers/\"\n[LoginService]\n    MessageUrl = \"https://play.example.org/helpers/motd.php\"\n    DestinationGuide = \"https://play.example.org/guide\"\n",
        );
        $machine->write(
            $machine->grid . '/OpenSim.ini',
            "[Search]\n    SearchURL = \"https://play.example.org/helpers/query.php\"\n[DataSnapshot]\n    DATA_SRV_demo = \"https://play.example.org/helpers/register.php\"\n[Messaging]\n    OfflineMessageURL = \"https://play.example.org/helpers/offline.php\"\n",
        );

        return Grid::find($machine->environment, 'demo');
    }

    test(
        'are the addresses the config of the grid gives them, with the format that is fixed or the engine\'s',
        function () {
            $grid = web_grid();
            $uris = [];
            foreach (['search', 'currency', 'landtool', 'register', 'offline', 'motd', 'guide'] as $key) {
                $uris[$key] = [
                    $grid->service($key)?->uri(),
                    $grid->service($key)?->kind(),
                    $grid->service($key)?->legacy(),
                ];
            }

            expect($uris)->toBe([
                'search' => ['https://play.example.org/helpers/query.php', Service::FIXED, 'query'],
                'currency' => ['https://play.example.org/helpers/currency.php', Service::FIXED, 'currency'],
                'landtool' => ['https://play.example.org/helpers/landtool.php', Service::FIXED, 'landtool'],
                'register' => ['https://play.example.org/helpers/register.php', Service::STANDARD, 'register'],
                'offline' => ['https://play.example.org/helpers/offline.php', Service::STANDARD, 'offline'],
                'motd' => ['https://play.example.org/helpers/motd.php', Service::STANDARD, 'motd'],
                'guide' => ['https://play.example.org/guide', Service::STANDARD, 'guide'],
            ]);
        },
    );

    test(
        'say where the engine has them in its own terms: the port, the standard address, whether it is served by Robust',
        function () {
            $motd = web_grid()->service('motd');

            expect($motd->port())
                ->toBe(Service::WEB)
                ->and($motd->isWeb())
                ->toBeTrue()
                ->and($motd->isServed())
                ->toBeFalse()
                ->and($motd->standard())
                ->toBe('GET /motd')
                ->and($motd->detail())
                ->toMatchArray(['web' => true, 'kind' => 'standard', 'legacy' => 'motd', 'standard' => 'GET /motd'])
                ->and(web_grid()->service('search')->standard())
                ->toBeNull();
        },
    );

    test('are not the grid\'s when its config does not give them', function () {
        $grid = service_grid(new Machine());

        expect($grid->service('search'))
            ->toBeNull()
            ->and($grid->service('currency'))
            ->toBeNull()
            ->and($grid->service('text-images'))
            ->toBeNull();
    });
});
