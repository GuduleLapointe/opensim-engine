<?php
/**
 * The config of a grid that is made, from the example of the core and the presets of its options, and of a simulator that is
 * configured again: what the operator added stays.
 */

use OpenSim\Config\ConfigReader;
use OpenSim\Controllers\RobustController;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridOptions;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\SimulatorConfig;
use OpenSim\Provisioning\SimulatorPlan;
use OpenSim\Testing\EnvironmentFake;

describe('A grid that is made', function () {
    /** A grid plan over a folder that has the Robust.HG.ini.example of a distribution. */
    $plan = function (): GridPlan {
        $root = test_tmp() . '/configure-' . bin2hex(random_bytes(4));
        mkdir("$root/bin", 0o755, true);
        mkdir("$root/grid", 0o755, true);
        file_put_contents(
            "$root/bin/Robust.HG.ini.example",
            "[Const]\n    BaseHostname = \"127.0.0.1\"\n[LoginService]\n    WelcomeMessage = \"Welcome to OpenSim!\"\n    SearchURL = \"\"\n[LibraryService]\n    LibraryName = \"OpenSim Library\"\n[NewThing]\n    FromTheDistribution = \"yes\"\n",
        );
        $plan = new GridPlan();
        $plan->binDir = "$root/bin";
        $plan->gridDir = "$root/grid";
        $plan->gridName = 'Alpha';
        $plan->gridNick = 'alpha';
        $plan->baseHostname = 'play.example.org';
        $plan->webUrl = 'https://play.example.org';
        $plan->etcDirectory = "$root/grid";
        $plan->dataDirectory = "$root/data";
        $plan->cacheDirectory = "$root/cache";
        $plan->logsDirectory = "$root/logs";

        return $plan;
    };

    test(
        'tells the viewers the web search and the pages of the helpers through the options, only where the grid says nothing',
        function () use ($plan) {
            $plan = $plan();
            $plan->helpers = true;
            $plan->helpersPath = '/helpers';
            $made = robust_made($plan);
            $options = fn(): GridOptions => new GridOptions(
                Grid::planned(EnvironmentFake::with(), $plan),
                layered: true,
                extra: $plan->variables(),
            );

            expect($made)
                ->toContain('search = "${Const|WebURL}/search"')
                ->toContain('SearchURL = "${Const|WebURL}/search"')
                ->toContain('economy = "${Const|WebURL}/helpers"')
                ->toContain('welcome = "${Const|WebURL}/welcome"')
                ->toContain('DestinationGuide = "${Const|WebURL}/helpers/guide.php"')
                ->toContain('MessageUrl = "${Const|WebURL}/helpers/motd.php"')
                ->toContain('web_profile_url = "${Const|WebURL}/profile/[AGENT_NAME]"');

            // The grid has its own: the option leaves it
            file_put_contents(
                $plan->robustIni(),
                str_replace('search = "${Const|WebURL}/search"', 'search = "https://other.example.org/q"', $made),
            );
            $options()->enable('search');

            expect((string) file_get_contents($plan->robustIni()))
                ->toContain('search = "https://other.example.org/q"')
                ->not->toContain('search = "${Const|WebURL}/search"');
        },
    );

    test('tells the gatekeeper of a hypergrid in the grid info, and nothing of it when it is not one', function () use (
        $plan,
    ) {
        $hypergrid = $plan();
        $hypergrid->enableHypergrid = true;
        file_put_contents(
            "{$hypergrid->binDir}/Robust.HG.ini.example",
            "[GridInfoService]\n    ; gatekeeper = \${Const|BaseURL}:\${Const|PublicPort}/\n",
        );

        expect(robust_made($hypergrid))->toMatch('/^\s*gatekeeper = \$\{Const\|BaseURL\}:\$\{Const\|PublicPort\}$/m');

        // Not a hypergrid: nothing to tell
        $plain = $plan();
        $plain->enableHypergrid = false;
        file_put_contents("{$plain->binDir}/Robust.ini.example", "[GridInfoService]\n    ; gatekeeper = x\n");
        expect(robust_made($plain))->not->toMatch('/^\s*gatekeeper\s*=/m')->not->toContain('GatekeeperURI');
    });

    test('serves the connectors that have their counterpart in the simulators, the ones of the options too', function () use (
        $plan,
    ) {
        $connectors = <<<'INI'
        [ServiceList]
            AssetServiceConnector = "x"
            ; OfflineIMServiceConnector = "${Const|PrivatePort}/OpenSim.Addons.OfflineIM.dll:OfflineIMServiceRobustConnector"
            ; GroupsServiceConnector = "${Const|PrivatePort}/OpenSim.Addons.Groups.dll:GroupsServiceRobustConnector"
            ; BakedTextureService = "${Const|PrivatePort}/OpenSim.Server.Handlers.dll:XBakesConnector"
            ; UserProfilesServiceConnector = "${Const|PublicPort}/OpenSim.Server.Handlers.dll:UserProfilesConnector"
            ; EstateDataService = "${Const|PrivatePort}/OpenSim.Server.Handlers.dll:EstateDataRobustConnector"
            ; HGGroupsServiceConnector = "${Const|PublicPort}/OpenSim.Addons.Groups.dll:HGGroupsServiceRobustConnector"
        INI;
        $served = function (bool $helpers, bool $hypergrid) use ($plan, $connectors): array {
            $grid = $plan();
            $grid->helpers = $helpers;
            $grid->enableHypergrid = $hypergrid;
            file_put_contents(
                $grid->binDir . '/' . ($hypergrid ? 'Robust.HG.ini.example' : 'Robust.ini.example'),
                $connectors,
            );
            robust_made($grid);

            return RobustController::connectorsIn($grid->robustIni());
        };

        // A grid alone, without helpers: the bakes, the estates, the profiles, the offline messages of Robust
        expect($served(false, false))->toBe([
            'AssetServiceConnector',
            'OfflineIMServiceConnector',
            'BakedTextureService',
            'UserProfilesServiceConnector',
            'EstateDataService',
        ]);

        // With helpers: the groups too, and the offline messages are the helpers'
        expect($served(true, false))
            ->toContain('GroupsServiceConnector')
            ->not->toContain('HGGroupsServiceConnector', 'OfflineIMServiceConnector');

        // A hypergrid: the groups of the hypergrid
        expect($served(false, true))->toContain('GroupsServiceConnector', 'HGGroupsServiceConnector');
    });

    test('starts from the distribution, and proposes what it proposes', function () use ($plan) {
        $text = robust_made($plan());

        expect($text)
            ->toContain('WelcomeMessage = "Welcome to Alpha, <USERNAME>!"')
            ->toContain('LibraryName = "Alpha Library"')
            ->toContain('BaseHostname = "play.example.org"')
            ->toContain('FromTheDistribution = "yes"');
    });
});

describe('Configuring a simulator that exists', function () {
    test('changes what the setup manages, keeps what the operator added', function () {
        $dir = test_tmp() . '/sim-' . bin2hex(random_bytes(4));
        mkdir("$dir/sims", 0o755, true);
        $plan = new SimulatorPlan();
        $plan->gridDir = $dir;
        $plan->gridNick = 'alpha';
        $plan->gridName = 'Alpha';
        $plan->slug = 'alpha_sim1';
        $plan->simulatorName = 'Sim1';
        $plan->baseHostname = 'play.example.org';
        $plan->httpPort = 9000;
        $config = new SimulatorConfig();

        $config->write($plan);
        file_put_contents(
            $plan->iniPath(),
            (string) file_get_contents($plan->iniPath()) . "\n[Gloebit]\nGLBEnvironment = sandbox\n",
        );
        $plan->httpPort = 9010;
        $config->write($plan);
        $text = (string) file_get_contents($plan->iniPath());

        expect($text)
            ->toContain('http_listener_port = 9010')
            ->not->toContain('http_listener_port = 9000')
            ->toContain("[Gloebit]\nGLBEnvironment = sandbox")
            ->toContain(';; Generated by opensim setup: simulator Sim1 of the grid Alpha')
            ->and(SimulatorConfig::readName($plan->iniPath()))
            ->toBe('Sim1');
    });

    test(
        'has what the options of its grid say: the profile service, and the search module and its registration when the grid has helpers',
        function () {
            $dir = test_tmp() . '/sim-' . bin2hex(random_bytes(4));
            mkdir("$dir/sims", 0o755, true);
            $make = function (bool $helpers) use ($dir) {
                $plan = new SimulatorPlan();
                $plan->gridDir = $dir;
                $plan->gridNick = 'alpha';
                $plan->gridName = 'Alpha';
                $plan->slug = $helpers ? 'alpha_with' : 'alpha_without';
                $plan->simulatorName = 'Sim';
                $plan->baseHostname = 'play.example.org';
                $plan->httpPort = 9000;
                $plan->optionsLayer = implode("\n", [
                    ...$helpers
                        ? [
                            '[Search]',
                            'Module = OpenSimSearch',
                            'SearchURL = "https://play.example.org/helpers/query.php"',
                            '[DataSnapshot]',
                            'index_sims = true',
                            'DATA_SRV_Alpha = "https://play.example.org/helpers/register.php"',
                        ]
                        : [],
                    '[UserProfiles]',
                    'ProfileServiceURL = "${Const|BaseURL}:${Const|PublicPort}"',
                ]);
                (new SimulatorConfig())->write($plan);

                return (string) file_get_contents($plan->iniPath());
            };

            expect($make(true))
                ->toContain(
                    "[Search]\nModule = OpenSimSearch\nSearchURL = \"https://play.example.org/helpers/query.php\"",
                )
                ->toContain('DATA_SRV_Alpha = "https://play.example.org/helpers/register.php"')
                ->toContain("[UserProfiles]\nProfileServiceURL = \"\${Const|BaseURL}:\${Const|PublicPort}\"")
                ->and($make(false))
                ->not->toContain('[Search]')
                ->toContain('ProfileServiceURL');
        },
    );
});

describe('What a grid tells its simulators of the connectors its Robust serves', function () {
    test('is the counterpart of each: the bakes, the groups, the groups of the hypergrid', function () {
        $layer = static function (bool $helpers, bool $hypergrid, bool $standalone = false): string {
            $plan = new GridPlan();
            $plan->gridDir = $plan->etcDirectory = test_tmp() . '/layer-' . bin2hex(random_bytes(4));
            $plan->gridNick = 'alpha';
            $plan->gridName = 'Alpha';
            $plan->webUrl = 'https://play.example.org';
            $plan->helpers = $helpers;
            $plan->enableHypergrid = $hypergrid;
            $plan->standalone = $standalone;
            $environment = EnvironmentFake::with();
            $plan->options = (new GridOptions(Grid::planned($environment, $plan)))->selection([]);

            return (new GridOptions(Grid::planned($environment, $plan)))->layer();
        };

        $none = $layer(false, false);
        expect($none)->toContain('[XBakes]')->not->toContain('[Groups]');

        $alone = $layer(true, false);
        expect($alone)
            ->toContain("[XBakes]\nURL = \"\${Const|PrivURL}:\${Const|PrivatePort}\"")
            ->toContain('ServicesConnectorModule = "Groups Remote Service Connector"')
            ->toContain('GroupsServerURI = "${Const|PrivURL}:${Const|PrivatePort}"')
            ->not->toContain('LocalService');

        $hypergrid = $layer(false, true);
        expect($hypergrid)
            ->toContain('ServicesConnectorModule = "Groups HG Service Connector"')
            ->toContain('LocalService = remote');

        // A standalone keeps them in the simulator itself, and has no Robust to keep the bakes
        $local = $layer(true, false, standalone: true);
        expect($local)
            ->toContain('ServicesConnectorModule = "Groups Local Service Connector"')
            ->not->toContain('GroupsServerURI')
            ->not->toContain('[XBakes]')
            ->and($layer(false, true, standalone: true))
            ->toContain('Groups HG Service Connector', 'LocalService = local');
    });

    test('is the estates of the grid, in the config of the architecture', function () {
        $dir = test_tmp() . '/estates-' . bin2hex(random_bytes(4));
        mkdir("$dir/config-include", 0o755, true);
        file_put_contents(
            "$dir/config-include/GridCommon.ini",
            "[EstateDataStore]\n    ;LocalServiceModule = \"OpenSim.Services.Connectors.dll:EstateDataRemoteConnector\"\n\n[EstateService]\n    EstateServerURI = \"x\"\n",
        );
        file_put_contents("$dir/Robust.HG.ini", "[ServiceList]\n    ; EstateDataService = \"x\"\n");
        $plan = new GridPlan();
        $plan->gridDir = $plan->etcDirectory = $dir;
        $plan->gridNick = 'alpha';
        $plan->gridName = 'Alpha';

        (new GridOptions(Grid::planned(EnvironmentFake::with(), $plan), layered: true))->enable('estates');

        expect(file_get_contents("$dir/config-include/GridCommon.ini"))
            ->toContain('    LocalServiceModule = "OpenSim.Services.Connectors.dll:EstateDataRemoteConnector"')
            ->not->toContain(';LocalServiceModule')
            ->and(file_get_contents("$dir/Robust.HG.ini"))
            ->toContain('EstateDataService = "x"');
    });
});
