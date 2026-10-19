<?php
/**
 * The roles of the regions of a grid (default, default for Hypergrid, fallback): given to a
 * region when its simulator is created, written in the Robust config as the key Robust looks for,
 * read back from it.
 */

use OpenSim\Config\InstanceSettings;
use OpenSim\Models\Grid;
use OpenSim\Models\Region;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\RobustConfig;
use OpenSim\Testing\EnvironmentFake;

/**
 * The Robust config a plan generates, from a minimal example.
 *
 * @param GridPlan $plan The plan.
 * @return string Generated Robust.HG.ini.
 */
function robust_generated(GridPlan $plan)
{
    $bin = test_tmp() . '/default-region-' . bin2hex(random_bytes(4));
    mkdir($bin);
    file_put_contents(
        "$bin/Robust.HG.ini.example",
        "[Const]\n[GridService]\n    ; Region_Welcome_Area = \"DefaultRegion, DefaultHGRegion\"\n",
    );
    $plan->binDir = $bin;
    $ini = (new RobustConfig())->generate($plan);
    unlink("$bin/Robust.HG.ini.example");
    rmdir($bin);

    return $ini;
}

describe('Region name', function () {
    test('accepts what a region can be called and refuses the rest', function () {
        expect(Region::validateName('Welcome'))->toBeNull();
        expect(Region::validateName('Welcome Area 2'))->toBeNull();
        expect(Region::validateName(''))->not->toBeNull();
        expect(Region::validateName('Bad"name'))->not->toBeNull();
    });

    test('is the key of the flags', function () {
        expect(Region::configKey('Welcome'))->toBe('Region_Welcome');
        expect(Region::configKey(' Welcome Area '))->toBe('Region_Welcome_Area');
        expect(Region::fromConfigKey('Region_Welcome_Area'))->toBe('Welcome Area');
    });

    test('is known among names the way Robust compares them', function () {
        $known = ['welcome area' => true, 'sandbox' => true];

        expect(Region::nameIsIn('Welcome Area', $known))->toBeTrue();
        expect(Region::nameIsIn('Welcome_Area', $known))->toBeTrue();
        expect(Region::nameIsIn('Welcome', $known))->toBeFalse();
    });
});

/** A Robust config as the distribution ships it: examples, and the comments of the next section before its header. */
function robust_example()
{
    return implode("\n", [
        '[GridService]',
        '    StorageProvider = "OpenSim.Data.MySQL.dll:MySqlRegionData"',
        '    ;; Region_Welcome_Area = "DefaultRegion, DefaultHGRegion"',
        '    MapTileDirectory = "x"',
        '',
        '[Other]',
        '    Setting = 1',
        '',
        ';; Documentation of the next section',
        ';; on two lines',
        '[FreeswitchService]',
        '    LocalServiceModule = "y"',
        '',
    ]);
}

/** A file holding a text, to give to something that reads files. */
function robust_file(string $text)
{
    $file = tempnam(test_tmp(), 'robust');
    file_put_contents($file, $text);

    return $file;
}

describe('Grid setup', function () {
    test('gives no region the roles: that is for the first simulator', function () {
        $plan = new GridPlan();
        $plan->baseHostname = 'localhost';

        expect(robust_generated($plan))->not->toMatch('/^\s*Region_\w+\s*=/m');
    });
});

describe('Roles of a region', function () {
    test('a grid can give are default, default HG (on Hypergrid) and fallback', function () {
        $grid = Grid::described(EnvironmentFake::with(), ['hypergrid' => true]);
        expect($grid->roles())->toBe(['DefaultRegion', 'DefaultHGRegion', 'FallbackRegion']);
        expect($grid->with(['hypergrid' => false])->roles())->toBe(['DefaultRegion', 'FallbackRegion']);
        expect($grid->with(['remote' => true])->roles())->toBe([]);
    });

    test('are found in the config, the commented example left out', function () {
        $text =
            "[GridService]\n    ; Region_Welcome_Area = \"DefaultRegion\"\n    Region_Home = \"DefaultRegion, Persistent\"\n    Region_Two = \"FallbackRegion\"\n";

        expect(Region::rolesIn($text))->toBe(['DefaultRegion', 'Persistent', 'FallbackRegion']);
        expect(Region::rolesIn("[GridService]\n    ; Region_Welcome_Area = \"DefaultRegion\"\n"))->toBe([]);
    });

    test('are written under the key of Robust', function () {
        $file = robust_file(robust_example());
        Region::giveRoles($file, 'Welcome Home', ['DefaultRegion', 'DefaultHGRegion']);
        $lines = explode("\n", (string) file_get_contents($file));
        unlink($file);

        $at = array_search('Region_Welcome_Home = "DefaultRegion, DefaultHGRegion, Persistent"', $lines, true);
        expect($at)->not->toBeFalse();
        expect($lines[$at - 1])->toContain('; Region_Welcome_Area');
    });

    test('are written before the next section', function () {
        $file = robust_file(
            str_replace('    ;; Region_Welcome_Area = "DefaultRegion, DefaultHGRegion"' . "\n", '', robust_example()),
        );
        Region::giveRoles($file, 'Welcome', ['DefaultRegion']);
        $text = (string) file_get_contents($file);
        unlink($file);

        expect($text)->toContain('Region_Welcome = "DefaultRegion, Persistent"');
        expect(strpos($text, 'Region_Welcome ='))->toBeLessThan(strpos($text, '[Other]'));
        expect($text)->toContain(";; Documentation of the next section\n;; on two lines\n[FreeswitchService]");
    });

    test('are kept when a region gets more', function () {
        $file = robust_file("[GridService]\nRegion_Welcome = \"DefaultRegion, Persistent\"\n");
        Region::giveRoles($file, 'Welcome', ['FallbackRegion']);
        $text = (string) file_get_contents($file);
        unlink($file);

        expect($text)->toContain('Region_Welcome = "DefaultRegion, Persistent, FallbackRegion"');
        expect(substr_count($text, 'Region_Welcome'))->toBe(1);
    });

    test('are read back from the config of the grid', function () {
        $file = robust_file("[GridService]\nRegion_Welcome = \"DefaultRegion, Persistent\"\n");
        $read = InstanceSettings::of($file);
        unlink($file);

        expect($read['regionFlags'])->toBe(['DefaultRegion', 'Persistent']);
    });
});

describe('landing region of a grid', function () {
    test('is needed until a region is default', function () {
        $grid = Grid::described(EnvironmentFake::with(), []);

        expect($grid->needsLandingRegion())->toBeTrue();
        expect($grid->missingRoles())->toBe(['DefaultRegion', 'DefaultHGRegion', 'FallbackRegion']);

        $grid = $grid->with(['regionFlags' => ['DefaultRegion']]);
        expect($grid->needsLandingRegion())->toBeTrue(); // the Hypergrid visitors have none yet
        expect($grid->missingRoles())->toBe(['DefaultHGRegion', 'FallbackRegion']);

        $grid = $grid->with(['regionFlags' => ['DefaultRegion', 'DefaultHGRegion']]);
        expect($grid->needsLandingRegion())->toBeFalse();
        expect($grid->missingRoles())->toBe(['FallbackRegion']);
    });

    test('skips the HG role without Hypergrid', function () {
        $grid = Grid::described(EnvironmentFake::with(), ['hypergrid' => false]);

        expect($grid->missingRoles())->toBe(['DefaultRegion', 'FallbackRegion']);
        expect($grid->with(['regionFlags' => ['DefaultRegion']])->needsLandingRegion())->toBeFalse();
    });

    test('is the business of the grid run elsewhere', function () {
        $remote = Grid::described(EnvironmentFake::with(), ['remote' => true]);

        expect($remote->needsLandingRegion())->toBeFalse();
        expect($remote->missingRoles())->toBe([]);
    });
});

describe('A name to propose', function () {
    test('is an alliteration the caller accepts', function () {
        $name = \OpenSim\Provisioning\RandomName::make(static fn(string $v): ?string => Region::validateName($v));

        expect($name)->toMatch('/^(\S+) (\S+)$/');
        expect(Region::validateName($name))->toBeNull();
        expect(strtolower($name[0]))->toBe(strtolower(explode(' ', $name)[1][0]));
    });

    test('is transliterated', function () {
        expect(\OpenSim\Provisioning\Slug::ascii('Joyeux Noël'))->toBe('Joyeux Noel');

        $name = \OpenSim\Provisioning\RandomName::make(static fn(string $v): ?string => null);
        expect($name)->toMatch('/^[\x20-\x7e]+$/');
    });

    test('is empty when no name is accepted', function () {
        expect(\OpenSim\Provisioning\RandomName::make(static fn(string $v): ?string => 'no'))->toBe('');
    });
});

describe('roles found in a Robust config', function () {
    test('are the ones of the Region_ keys, however the value is written', function () {
        $text =
            "[GridService]\r\n" .
            "    ; Region_Example = \"DefaultRegion, FallbackRegion\"\r\n" .
            "Region_Welcome = \"DefaultRegion, DefaultHGRegion, Persistent\"  ; the landing region\r\n" .
            "\tRegion_Second_Place = DefaultHGRegion\r\n" .
            "Region_0a1b2c3d-0000-4000-8000-000000000001 = \"FallbackRegion\" # by id\r\n" .
            "[Other]\r\n" .
            "NotARegion = \"DefaultRegion\"\r\n";

        expect(Region::rolesIn($text))->toBe(['DefaultRegion', 'DefaultHGRegion', 'Persistent', 'FallbackRegion']);
    });

    test('are none in a config that has only the examples', function () {
        expect(
            Region::rolesIn("[GridService]\n    ; Region_Welcome_Area = \"DefaultRegion, DefaultHGRegion\"\n"),
        )->toBe([]);
    });
});
