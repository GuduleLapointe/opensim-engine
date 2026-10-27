<?php
/**
 * The web side of a grid: where its services are, its Helpers.ini, what the web server needs.
 */

use OpenSim\Provisioning\Web\HelpersConfig;
use OpenSim\Provisioning\Web\Services;
use OpenSim\Provisioning\Web\Snippets;

/** Whether the file system holds two files whose names differ by the case only. */
function web_test_fs_tells_cases(): bool
{
    $dir = test_tmp() . '/case-' . bin2hex(random_bytes(4));
    mkdir($dir);
    touch("$dir/a");
    touch("$dir/A");

    return count(scandir($dir)) === 4;
}

describe('Services', function () {
    test('are under /helpers by default', function () {
        $services = new Services();

        expect($services->path('query'))->toBe('/helpers/query.php');
        expect($services->path('currency'))->toBe('/helpers/currency.php');
        expect($services->url('https://play.example.org/', 'offline'))->toBe(
            'https://play.example.org/helpers/offline.php',
        );
    });

    test('follow the path of the economy', function () {
        $services = new Services('helper/');

        expect($services->path('currency'))->toBe('/helper/currency.php');
        expect($services->path('query.php'))->toBe('/helper/query.php');
        expect($services->path('guide'))->toBe('/helper/guide.php');
    });

    test('have the path of an address of the Robust config, with its constants', function () {
        expect(Services::pathOf('${Const|WebURL}/helper/'))
            ->toBe('/helper')
            ->and(Services::pathOf('https://play.example.org/currency'))
            ->toBe('/currency')
            ->and(Services::pathOf('${Const|WebURL}'))
            ->toBe('/');
    });
});

describe('Helpers.ini', function () {
    test(
        'is a template that sets nothing: every setting is commented out, none is one of the official files',
        function () {
            $text = (string) file_get_contents(dirname(__DIR__, 2) . '/share/presets/helpers/Helpers.ini');

            expect($text)->toContain('[Helpers]');
            expect($text)->toContain(';; GuideSource = "destinations.txt"');
            expect($text)->toContain(';; SearchConnectionString = ');
            // The currency is the business of the modules (Gloebit.ini...), not of this file
            expect($text)->not->toMatch('/Currency/');
            // What has its place in OpenSim.ini or Robust.ini is not copied there
            expect($text)->not->toMatch('/^\s*(grid_name|login_uri|web_url|gridname|ConnectionString)\b/m');
            expect($text)->not->toMatch('/^\[(robust_db|Urls)\]/m');
        },
    );

    test('is the name of the file, in the folder of the grid', function () {
        expect(HelpersConfig::path('/etc/opensim/grids/alpha/'))->toBe('/etc/opensim/grids/alpha/Helpers.ini');
    });

    test('is the only name in the folder of the grid: helpers.ini of the first setups becomes it', function () {
        $dir = test_tmp() . '/helpers-case-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/helpers.ini", "[Helpers]\npath = \"/helper\"\n");

        expect(HelpersConfig::unifyCase($dir))
            ->toBe([['helpers.ini', 'Helpers.ini']])
            ->and(scandir($dir))
            ->toContain('Helpers.ini')
            ->not->toContain('helpers.ini')
            ->and(file_get_contents("$dir/Helpers.ini"))
            ->toContain('/helper')
            ->and(HelpersConfig::unifyCase($dir))
            ->toBe([]);
    });

    test('keeps the second file aside when the folder has both, where the system tells the cases apart', function () {
        $dir = test_tmp() . '/helpers-both-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/Helpers.ini", 'new');
        file_put_contents("$dir/helpers.ini", 'old');

        expect(HelpersConfig::unifyCase($dir))
            ->toBe([['helpers.ini', 'helpers.ini~']])
            ->and(file_get_contents("$dir/Helpers.ini"))
            ->toBe('new')
            ->and(file_get_contents("$dir/helpers.ini~"))
            ->toBe('old');
    })->skip(fn() => !web_test_fs_tells_cases(), 'the file system does not tell the cases apart');

    test(
        'gives the web address and the path of the helpers from the Robust config, the economy being their path',
        function () {
            $dir = test_tmp() . '/helpers-ini-' . bin2hex(random_bytes(4));
            mkdir($dir);
            file_put_contents(
                "$dir/Robust.HG.ini",
                "[Const]\n    WebURL = \"https://play.example.org\"\n[GridInfoService]\n    economy = \"\${Const|WebURL}/helper\"\n",
            );

            expect(HelpersConfig::webUrl($dir))
                ->toBe('https://play.example.org')
                ->and(HelpersConfig::basePath($dir))
                ->toBe('/helper')
                ->and(HelpersConfig::services($dir)->path('currency'))
                ->toBe('/helper/currency.php');

            $bare = test_tmp() . '/helpers-ini-' . bin2hex(random_bytes(4));
            mkdir($bare);
            expect(HelpersConfig::basePath($bare))->toBe('/helpers')->and(HelpersConfig::webUrl($bare))->toBe('');
        },
    );
});

describe('Pages', function () {
    test('have a default path', function () {
        expect((new Services())->path('home'))
            ->toBe('/')
            ->and((new Services())->path('welcome'))
            ->toBe('/welcome')
            ->and((new Services())->path('health'))
            ->toBe('/health');
    });
});

describe('Web server snippets', function () {
    test('renders the selected preset with grid and runtime paths', function () {
        $dir = test_tmp() . '/web-snippet-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/Robust.ini", "[Const]\nWebURL = \"https://grid.example.test\"\n");

        $snippet = Snippets::render('caddy', 'test_grid', $dir, '/srv/opensim-helpers/html', '/run/php/test.sock');

        expect($snippet)
            ->toContain('grid.example.test')
            ->toContain('/srv/opensim-helpers/html')
            ->toContain('/run/php/test.sock')
            ->toContain('OPENSIM_GRID test_grid')
            ->not->toContain('{{');
    });

    test('rejects an unknown web server', function () {
        expect(fn() => Snippets::render('unknown', 'test_grid', '/tmp/grid'))
            ->toThrow(InvalidArgumentException::class);
    });
});
