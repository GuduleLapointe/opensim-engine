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
                ->and($grid->service('asset')->url())
                ->toBe('http://localhost:8003')
                ->and($grid->service('asset')->database())
                ->toBe(Database::Assets)
                ->and($grid->service('asset')->id())
                ->toBe('demo.asset')
                ->and($grid->service('profile'))
                ->toBeNull()
                ->and($grid->service('nonsense'))
                ->toBeNull();
        },
    );

    test('have a detail with the standard names', function () {
        $detail = service_grid(new Machine())->service('grid')->detail();

        expect($detail)->toMatchArray([
            'id' => 'demo.grid',
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

        expect(Service::find($machine->environment, 'demo.asset')?->key())
            ->toBe('asset')
            ->and(Service::find($machine->environment, 'demo.profile'))
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
            ->and($beta->service('asset')->url())
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

            expect($alpha->service('asset')->url())
                ->toBe('http://assets.example.org:8003')
                ->and($alpha->service('asset')->isServed())
                ->toBeTrue()
                ->and($alpha->service('profile')->url())
                ->toBe('https://profiles.example.org')
                ->and($alpha->service('profile')->isServed())
                ->toBeFalse()
                ->and($alpha->service('profile')->isExternal())
                ->toBeTrue()
                ->and($beta->service('profile'))
                ->toBeNull()
                ->and($beta->service('asset')->url())
                ->toBe('http://localhost:8003');
        },
    );
});

describe('web services of a grid', function () {
    /** The grid `demo` with the addresses its config gives to the web services. */
    function web_grid(bool $helpers = false): Grid
    {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/Robust.HG.ini',
            file_get_contents($machine->grid . '/Robust.HG.ini') .
                "\n[Const]\n    WebURL = \"https://play.example.org\"\n[GridInfoService]\n    economy = \"https://play.example.org/helpers/\"\n    search = \"https://play.example.org/search\"\n    GridStatusRSS = \"https://play.example.org/status.rss\"\n[LoginService]\n    MessageUrl = \"https://play.example.org/helpers/motd.php\"\n    DestinationGuide = \"https://play.example.org/guide\"\n",
        );
        $machine->write(
            $machine->grid . '/OpenSim.ini',
            "[Search]\n    SearchURL = \"https://play.example.org/helpers/query.php\"\n[DataSnapshot]\n    DATA_SRV_demo = \"https://play.example.org/helpers/register.php\"\n[Messaging]\n    OfflineMessageURL = \"https://play.example.org/helpers/offline.php\"\n",
        );
        $helpers &&
            $machine->write(
                $machine->grid . '/Helpers.ini',
                "[Helpers]\n    AssetsURL = \"https://assets.example.org/a\"\n    GridStatusURL = \"https://play.example.org/helpers/status-page\"\n",
            );

        return Grid::find($machine->environment, 'demo');
    }

    test(
        'are the addresses the config of the grid gives them, with the format that is fixed or the engine\'s',
        function () {
            $grid = web_grid();
            $uris = [];
            foreach (
                ['query', 'search', 'currency', 'landtool', 'register', 'offline', 'motd', 'guide', 'grid-status-rss']
                as $key
            ) {
                $uris[$key] = [
                    $grid->service($key)?->url(),
                    $grid->service($key)?->kind(),
                    $grid->service($key)?->legacy(),
                ];
            }

            expect($uris)->toBe([
                'query' => ['https://play.example.org/helpers/query.php', Service::FIXED, 'query'],
                'search' => ['https://play.example.org/search', Service::PAGE, null],
                'currency' => ['https://play.example.org/helpers/currency.php', Service::FIXED, 'currency'],
                'landtool' => ['https://play.example.org/helpers/landtool.php', Service::FIXED, 'landtool'],
                'register' => ['https://play.example.org/helpers/register.php', Service::FIXED, 'register'],
                'offline' => ['https://play.example.org/helpers/offline.php', Service::FIXED, 'offline'],
                'motd' => ['https://play.example.org/helpers/motd.php', Service::STANDARD, 'motd'],
                'guide' => ['https://play.example.org/guide', Service::STANDARD, 'guide'],
                'grid-status-rss' => ['https://play.example.org/status.rss', Service::FIXED, null],
            ]);
        },
    );

    test(
        'are all asked the same way, wherever the grid says them, the running grid before its config before the helpers',
        function () {
            $machine = new Machine();
            $machine->write(
                $machine->grid . '/Robust.HG.ini',
                file_get_contents($machine->grid . '/Robust.HG.ini') .
                    "\n[Const]\n    WebURL = \"https://play.example.org\"\n[GridInfoService]\n    economy = \"https://old.example.org/helpers\"\n[LoginService]\n    SearchURL = \"https://play.example.org/websearch\"\n",
            );
            $machine->write(
                $machine->grid . '/Helpers.ini',
                "[Helpers]\n    AssetsURL = \"https://assets.example.org/a\"\n    GridStatusURL = \"https://play.example.org/helpers/status-page\"\n",
            );
            $machine->http['http://localhost:8002/get_grid_info'] =
                '<gridinfo><gridname>Demo</gridname><economy>https://play.example.org/helpers</economy><welcome>https://play.example.org/splash</welcome><GridStatusRSS>https://play.example.org/live.rss</GridStatusRSS><mood>free value</mood></gridinfo>';
            $grid = Grid::find($machine->environment, 'demo');

            $grid->refreshInfo();
            $addresses = $grid->addresses(web: true);

            expect($addresses)
                ->toMatchArray([
                    'currency' => 'https://play.example.org/helpers/currency.php',
                    'landtool' => 'https://play.example.org/helpers/landtool.php',
                    'welcome' => 'https://play.example.org/splash',
                    'grid-status-rss' => 'https://play.example.org/live.rss',
                    'search' => 'https://play.example.org/websearch',
                    'grid-status' => 'https://play.example.org/helpers/status-page',
                    'assets' => 'https://assets.example.org/a',
                    'query' => 'https://play.example.org/helpers/query.php',
                    'parser' => 'https://play.example.org/helpers/parser.php',
                    'events-parser' => 'https://play.example.org/helpers/eventsparser.php',
                    'text-images' => 'https://play.example.org/helpers/textgen.php',
                ])
                ->and($grid->address('currency'))
                ->toBe('https://play.example.org/helpers/currency.php')
                ->and($grid->address('nonsense'))
                ->toBeNull()
                ->and([
                    $grid->economy(),
                    $grid->helpersUrl(),
                    $grid->searchUrl(),
                    $grid->searchApi(),
                    $grid->assetsUrl(),
                    $grid->gridStatusRss(),
                    $grid->mapUrl(),
                ])
                ->toBe([
                    'https://play.example.org/helpers',
                    'https://play.example.org/helpers',
                    'https://play.example.org/websearch',
                    'https://play.example.org/helpers/query.php',
                    'https://assets.example.org/a',
                    'https://play.example.org/live.rss',
                    null,
                ])
                ->and(
                    array_map(fn(string $key): ?string => $grid->service($key)->source(), [
                        'currency',
                        'welcome',
                        'search',
                        'grid-status',
                        'query',
                    ]),
                )
                ->toBe(['live', 'live', 'config', 'config', 'default'])
                ->and($grid->addresses())
                ->toHaveKeys(['currency', 'asset', 'grid']);
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
                ->toMatchArray([
                    'web' => true,
                    'kind' => 'standard',
                    'legacy' => 'motd',
                    'standard' => 'GET /motd',
                    'endpoint' => 'GET /motd',
                    'source' => 'config',
                ])
                ->and(web_grid()->service('query')->standard())
                ->toBeNull()
                ->and(web_grid()->service('query')->endpoint())
                ->toBe('POST /search/query')
                ->and(web_grid()->service('register')->endpoint())
                ->toBe('GET /search/register')
                ->and(web_grid()->service('offline')->kind())
                ->toBe('fixed')
                ->and(web_grid()->service('currency')->endpoint())
                ->toBe('POST /currency.php')
                ->and(web_grid()->service('parser')->standard())
                ->toBe('POST /tasks/search-parse/runs');
        },
    );

    test('are not the grid\'s when its config does not give them and it has no helpers', function () {
        $grid = service_grid(new Machine());

        expect($grid->service('query'))
            ->toBeNull()
            ->and($grid->service('currency'))
            ->toBeNull()
            ->and($grid->service('text-images'))
            ->toBeNull()
            ->and($grid->addresses(web: true))
            ->toBe([]);
    });
});
