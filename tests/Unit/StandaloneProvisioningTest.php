<?php

declare(strict_types=1);

/**
 * A standalone is a grid whose only simulator serves the grid itself: no Robust, the database of the grid is the one of the simulator,
 * no other simulator can join it. What the setup plans and writes for it.
 */

use OpenSim\Config\ConfigReader;
use OpenSim\Provisioning\GridConf;
use OpenSim\Provisioning\GridPlanner;
use OpenSim\Provisioning\GridShared;
use OpenSim\Provisioning\SimulatorConfig;
use OpenSim\Provisioning\SimulatorMaster;

/** The folder of a standalone grid beside a core that has the files of a standalone, and its description. */
function standalone_folder(bool $hypergrid = true): array
{
    $root = test_tmp() . '/standalone-' . bin2hex(random_bytes(4));
    $bin = "$root/core/bin";
    $dir = "$root/etc/grids/solo";
    mkdir("$bin/config-include", 0o755, true);
    mkdir($dir, 0o755, true);
    file_put_contents("$bin/OpenSimDefaults.ini", "[Startup]\n    Other = core\n");
    file_put_contents("$bin/OpenSim.ini.example", "[Startup]\n;Other = example\n");
    $architecture = $hypergrid ? 'StandaloneHypergrid.ini' : 'Standalone.ini';
    file_put_contents(
        "$bin/config-include/$architecture",
        "[Includes]\n    Include-Common = \"config-include/StandaloneCommon.ini\"\n",
    );
    file_put_contents(
        "$bin/config-include/StandaloneCommon.ini",
        <<<'INI'
        [Includes]
            Include-Storage = "config-include/storage/SQLiteStandalone.ini";
            Include-FlotsamCache = "config-include/FlotsamCache.ini"
        [Hypergrid]
            ; GatekeeperURI = "${Const|BaseURL}:${Const|PublicPort}"
        [GridInfoService]
            gridname = "the lost continent of hippo"
            gridnick = "hippogrid"
        INI
        . "\n",
    );
    file_put_contents("$bin/config-include/FlotsamCache.ini", "[AssetCache]\n    CacheDirectory = ./assetcache\n");
    file_put_contents("$bin/config-include/osslDefaultEnable.ini", "[OSSL]\n");
    file_put_contents("$bin/config-include/osslEnable.ini.example", "[OSSL]\n    AllowOSFunctions = false\n");
    file_put_contents(
        "$dir/solo.conf",
        "[Grid]\nGridName = Solo World\nGridNick = solo\nStandalone = true\nHypergrid = " .
            ($hypergrid ? 'true' : 'false') .
            "\nBaseHostname = solo.example.org\nPublicPort = 9000\n",
    );

    return [$dir, $bin];
}

describe('The grid of a standalone', function () {
    test('is asked for, has no Robust, and answers on one port', function () {
        $v = planner()->resolve(['standalone' => true, 'public_port' => 9000]);
        $plan = planner()->plan(['standalone' => true, 'public_port' => 9000]);

        expect($v['standalone'])
            ->toBeTrue()
            ->and($plan->standalone)
            ->toBeTrue()
            ->and([$plan->publicPort, $plan->privatePort])
            ->toBe([9000, 9000])
            ->and(planner()->plan()->standalone)
            ->toBeFalse();
    });

    test('has its database named after its nick, not after a Robust it does not have', function () {
        expect(planner()->resolve(['standalone' => true])['database_name'])
            ->toBe('amy')
            ->and(planner()->resolve()['database_name'])
            ->toBe('amy_robust');
    });

    test('is described by its folder: its address, its port and its database', function () {
        $plan = planner()->plan([
            'standalone' => true,
            'public_port' => 9000,
            'database_name' => 'solo_db',
            'database_password' => 'secret',
        ]);
        $plan->etcDirectory = $plan->gridDir = test_tmp() . '/soloconf-' . bin2hex(random_bytes(4)) . '/amy';

        $conf = ConfigReader::file((new GridConf())->write($plan))['Grid'];

        expect($conf)->toMatchArray([
            'Standalone' => 'true',
            'PublicPort' => '9000',
            'DatabaseName' => 'solo_db',
            'DatabaseUser' => 'opensim',
            'DatabasePassword' => 'secret',
        ]);
    });
});

describe('The shared files of a standalone', function () {
    test('are those of a standalone, from the architecture to its common settings', function () {
        expect(GridShared::files(true, true))
            ->toContain('config-include/StandaloneHypergrid.ini', 'config-include/StandaloneCommon.ini')
            ->not->toContain('config-include/GridCommon.ini')
            ->and(GridShared::files(false, true))
            ->toContain('config-include/Standalone.ini')
            ->and(GridShared::files(true))
            ->toContain('config-include/GridHypergrid.ini', 'config-include/GridCommon.ini');
    });

    test('give the grid its own database, its own names and its gatekeeper', function () {
        [$dir, $bin] = standalone_folder();

        (new GridShared())->merge($dir, $bin, true, standalone: true);
        (new GridShared())->prepare($dir, $bin, true, true);
        $text = file_get_contents("$dir/config-include/StandaloneCommon.ini");

        expect($text)
            ->toContain(';    Include-Storage = "config-include/storage/SQLiteStandalone.ini"')
            ->toContain('gridname = "Solo World"')
            ->toContain('gridnick = "solo"')
            ->toContain('Include-FlotsamCache = "' . $dir . '/config-include/FlotsamCache.ini"')
            ->and(preg_match('/^\s*GatekeeperURI\s*=/m', $text))
            ->toBe(1)
            ->and(file_get_contents("$dir/config-include/StandaloneHypergrid.ini"))
            ->toContain('Include-Common = "' . $dir . '/config-include/StandaloneCommon.ini"');
    });

    test('keep a name the grid changed, and have no gatekeeper when it is not a hypergrid', function () {
        [$dir, $bin] = standalone_folder(false);
        $common = "$dir/config-include/StandaloneCommon.ini";
        mkdir("$dir/config-include", 0o755, true);
        file_put_contents($common, "[GridInfoService]\n    gridname = \"Mine\"\n");
        file_put_contents(
            "$dir/solo.conf",
            "[Grid]\nGridName = Solo World\nGridNick = solo\nStandalone = true\nHypergrid = false\n",
        );

        (new GridShared())->merge($dir, $bin, false, standalone: true);
        $text = file_get_contents($common);

        expect($text)
            ->toContain('gridname = "Mine"')
            ->toContain('gridnick = "solo"')
            ->and(preg_match('/^\s*GatekeeperURI\s*=/m', $text))
            ->toBe(0);
    });
});

describe('The master of a standalone', function () {
    test('includes the architecture of a standalone, with the address and the port of its description', function () {
        [$dir, $bin] = standalone_folder();

        $ini = ConfigReader::load((string) (new SimulatorMaster())->ensure($dir, $bin), expand: false);

        expect($ini['Architecture']['Include-Architecture'])
            ->toBe("$dir/config-include/StandaloneHypergrid.ini")
            ->and($ini['Const'])
            ->toMatchArray(['BaseHostname' => 'solo.example.org', 'PublicPort' => '9000']);
    });
});

describe('The config of the simulator of a standalone', function () {
    /** The plan of a simulator of a standalone whose owner has no account yet. */
    function standalone_simulator_plan(): \OpenSim\Provisioning\SimulatorPlan
    {
        $plan = new \OpenSim\Provisioning\SimulatorPlan();
        $plan->standalone = true;
        $plan->gridDir = test_tmp() . '/solosim-' . bin2hex(random_bytes(4));
        $plan->gridNick = 'solo';
        $plan->gridName = 'Solo World';
        $plan->slug = 'solo_welcome';
        $plan->simulatorName = 'Welcome';
        $plan->estateName = 'Solo Estate';
        $plan->estateOwner = 'Ada Love';
        $plan->createOwner = true;
        $plan->ownerEmail = 'ada@example.org';
        $plan->ownerPassword = 'secret123';
        $plan->ownerUuid = 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa';
        mkdir($plan->gridDir, 0o755, true);

        return $plan;
    }

    test('has the account of its owner made by OpenSim at its first start, then forgets the password', function () {
        $plan = standalone_simulator_plan();
        $path = (new SimulatorConfig())->write($plan);

        expect(ConfigReader::file($path)['Estates'])->toMatchArray([
            'DefaultEstateOwnerName' => 'Ada Love',
            'DefaultEstateOwnerUUID' => 'aaaaaaaa-1111-4111-8111-aaaaaaaaaaaa',
            'DefaultEstateOwnerEMail' => 'ada@example.org',
            'DefaultEstateOwnerPassword' => 'secret123',
        ]);

        (new SimulatorConfig())->forgetOwnerPassword($path);

        expect(ConfigReader::file($path)['Estates'])
            ->not->toHaveKey('DefaultEstateOwnerPassword')
            ->toHaveKey('DefaultEstateOwnerName');
    });

    test('includes the architecture of a standalone when it has no master', function () {
        $plan = standalone_simulator_plan();

        expect((new SimulatorConfig())->render($plan))->toContain(
            'Include-Architecture = "' . $plan->gridDir . '/config-include/StandaloneHypergrid.ini"',
        );
    });

    test('has no account to make when the owner has one', function () {
        $plan = standalone_simulator_plan();
        $plan->createOwner = false;

        expect((new SimulatorConfig())->render($plan))->not->toContain('DefaultEstateOwnerPassword');
    });
});
