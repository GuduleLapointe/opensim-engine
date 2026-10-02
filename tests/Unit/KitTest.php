<?php
/**
 * What the engine reads of the OpenSim kit: the profile, the Robust config of a grid, its helpers.ini.
 */

define('OPENSIM_ENGINE', true);
require_once dirname(__DIR__, 2) . '/class-kit.php';

/**
 * A setup of the kit in a temporary tree: a profile, one grid.
 *
 * @param string $helpers The content of helpers.ini of the grid, none when empty.
 * @return array{0:string,1:string} The opensim.conf, the grid folder.
 */
function kit_tree(string $helpers = ''): array
{
    $root = sys_get_temp_dir() . '/kit-' . bin2hex(random_bytes(4));
    $grid = "$root/etc/grids/Alpha";
    mkdir($grid, 0o755, true);
    file_put_contents(
        "$root/opensim.conf",
        "[Defaults]\nDefaultProfile = 0.9.3.0\nSystemUser = opensim\n\n[0.9.3.0]\nEtcRoot = $root/etc\n",
    );
    file_put_contents(
        "$grid/Robust.HG.ini",
        <<<'INI'
        ; Robust of the grid
        [Const]
            BaseHostname = "play.example.org"
            BaseURL = "http://${Const|BaseHostname}"
            WebURL = "https://${Const|BaseHostname}"
            PublicPort = 8002
        [DatabaseService]
            ConnectionString = "Data Source=localhost;Database=alpha_robust;User ID=opensim;Password=s3cret;Old Guids=true;"
        [Hypergrid]
            GatekeeperURI = "${Const|BaseURL}:${Const|PublicPort}"
        [GridInfoService]
            gridname = "Alpha World"
        INI
        ,
    );
    if ($helpers !== '') {
        file_put_contents("$grid/helpers.ini", $helpers);
    }

    return ["$root/opensim.conf", $grid];
}

describe('OpenSim_Kit', function () {
    test('reads an ini the way OpenSimulator writes it, constants expanded', function () {
        [, $grid] = kit_tree();

        $ini = OpenSim_Kit::read_ini("$grid/Robust.HG.ini");

        expect($ini['Const']['BaseURL'])->toBe('http://play.example.org');
        expect($ini['Hypergrid']['GatekeeperURI'])->toBe('http://play.example.org:8002');
        expect($ini['GridInfoService']['gridname'])->toBe('Alpha World');
        expect(OpenSim_Kit::read_ini('/nonexistent.ini'))->toBe([]);
    });

    test('gives the default profile and the grids it has', function () {
        [$conf] = kit_tree();

        $profile = OpenSim_Kit::profile($conf);

        expect($profile['SystemUser'])->toBe('opensim');
        expect(array_keys(OpenSim_Kit::grids($profile)))->toBe(['Alpha']);
        expect(OpenSim_Kit::grid_nick(null, $conf))->toBe('Alpha');
        expect(OpenSim_Kit::grid_nick('Beta', $conf))->toBeNull();
    });

    test('takes the settings of the helpers from the Robust config of the grid', function () {
        [$conf] = kit_tree();

        $settings = OpenSim_Kit::settings(null, $conf);

        expect($settings['grid_name'])->toBe('Alpha World');
        expect($settings['login_uri'])->toBe('http://play.example.org:8002');
        expect($settings['web_url'])->toBe('https://play.example.org');
        expect($settings['hypergrid'])->toBeTrue();
        expect($settings['databases']['robust_db'])->toBe([
            'hostname' => 'localhost',
            'prefix' => 'alpha_robust',
            'user' => 'opensim',
            'password' => 's3cret',
        ]);
        // Without anything in helpers.ini every database is the one of Robust
        expect($settings['databases']['search_db'])->toBe($settings['databases']['robust_db']);
    });

    test('lets helpers.ini give another database, other urls and other options', function () {
        [$conf] = kit_tree(<<<'INI'
        [Helpers]
        path = "/helper"
        mail_sender = "no-reply@example.org"
        currency_provider = "gloebit"
        [search_db]
        hostname = "db2"
        prefix = "ossearch"
        user = "search"
        password = "pw"
        [Urls]
        search = "/search"
        INI);

        $settings = OpenSim_Kit::settings('Alpha', $conf);
        $constants = OpenSim_Kit::constants($settings);

        expect($constants['SEARCH_DB_HOST'])->toBe('db2');
        expect($constants['SEARCH_DB_NAME'])->toBe('ossearch');
        expect($constants['OPENSIM_DB_NAME'])->toBe('alpha_robust');
        expect($constants['OPENSIM_MAIL_SENDER'])->toBe('no-reply@example.org');
        expect($constants['CURRENCY_PROVIDER'])->toBe('gloebit');
        expect(OpenSim_Kit::script_path($settings, 'query.php'))->toBe('/search');
        expect(OpenSim_Kit::script_path($settings, 'guide.php'))->toBe('/helper/guide.php');
        expect($constants['CURRENCY_HELPER_URL'])->toBe('https://play.example.org/helper/currency.php');
    });

    test('has the constants the helper scripts expect', function () {
        [$conf] = kit_tree();

        $constants = OpenSim_Kit::constants(OpenSim_Kit::settings(null, $conf));

        expect($constants)->toHaveKeys([
            'OPENSIM_GRID_NAME',
            'OPENSIM_LOGIN_URI',
            'OPENSIM_DB_HOST',
            'SEARCH_DB_NAME',
            'CURRENCY_DB_USER',
            'OFFLINE_DB_PASS',
            'ROBUST_DB_HOST',
            'CURRENCY_HELPER_URL',
        ]);
        expect($constants['OPENSIM_USE_UTC_TIME'])->toBeTrue();
        expect($constants['CURRENCY_HELPER_URL'])->toBe('https://play.example.org/helpers/currency.php');
    });

    test('finds nothing for a grid that does not exist, or when there are several without a choice', function () {
        [$conf, $grid] = kit_tree();
        expect(OpenSim_Kit::settings('Nowhere', $conf))->toBeNull();

        mkdir(dirname($grid) . '/Beta');
        copy("$grid/Robust.HG.ini", dirname($grid) . '/Beta/Robust.ini');
        expect(OpenSim_Kit::grid_nick(null, $conf))->toBeNull();
        expect(OpenSim_Kit::grid_nick('Beta', $conf))->toBe('Beta');
    });

    test('reads a connection string', function () {
        expect(OpenSim_Kit::connection('Data Source=db;Database=x;User ID=u;Password=p;Old Guids=true;'))->toBe([
            'hostname' => 'db',
            'prefix' => 'x',
            'user' => 'u',
            'password' => 'p',
        ]);
    });
});
