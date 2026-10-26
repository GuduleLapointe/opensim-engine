<?php

declare(strict_types=1);

use OpenSim\Provisioning\QuickSetup;
use OpenSim\Testing\Machine;

function port_setup_answers(array $overrides = []): array
{
    return $overrides + [
        'type' => 'grid',
        'name' => 'Port Test',
        'login_uri' => 'http://grid.example.test:18102',
        'avatar' => 'Ada Test',
        'password' => 'test-password',
        'password2' => 'test-password',
        'private_port' => '18103',
        'grid_console_port' => '18104',
        'http_port' => '19140',
        'console_port' => '19144',
        'region_port' => '19141',
    ];
}

describe('setup ports', function () {
    test('uses chosen TCP and UDP ports', function () {
        $result = QuickSetup::plan((new Machine())->environment, port_setup_answers());
        expect($result['problems'])->toBe([])
            ->and($result['setup']['grid'])->toMatchArray(['private_port' => 18103, 'console_port' => 18104])
            ->and($result['setup']['simulators'][0])->toMatchArray(['http_port' => 19140, 'console_port' => 19144])
            ->and($result['setup']['simulators'][0]['regions'][0]['port'])->toBe(19141);
    });

    test('keeps automatic ten-port blocks', function () {
        $result = QuickSetup::plan((new Machine())->environment, port_setup_answers([
            'http_port' => '19160', 'console_port' => '', 'region_port' => '',
        ]));
        expect($result['problems'])->toBe([])
            ->and($result['setup']['simulators'][0]['console_port'])->toBe(19164)
            ->and($result['setup']['simulators'][0]['regions'][0]['port'])->toBe(19161);
    });

    test('reports invalid fields by question', function () {
        $result = QuickSetup::plan((new Machine())->environment, port_setup_answers([
            'private_port' => 'bad', 'grid_console_port' => 'bad', 'http_port' => 'bad',
            'console_port' => 'bad', 'region_port' => 'bad',
        ]));
        expect(array_keys($result['problems']))->toContain('private_port', 'grid_console_port', 'http_port', 'console_port', 'region_port');
    });

    test('offers port overrides before confirmation', function () {
        $env = (new Machine())->environment;
        $type = QuickSetup::next($env, ['mode' => 'prepare']);
        $form = QuickSetup::next($env, ['mode' => 'prepare', 'state' => $type['state'], 'answers' => ['type' => 'grid']]);
        expect(array_column($form['fields'], 'name'))->toContain('private_port', 'grid_console_port', 'http_port', 'console_port', 'region_port');
    });
    test('saves confirmed ports', function () {
        $env = (new Machine())->environment;
        $screen = QuickSetup::next($env, ['mode' => 'prepare']);
        foreach ([['type' => 'grid'], port_setup_answers(), [], [], [
            'database_host' => 'localhost', 'database_user' => 'opensim', 'database_password' => 'test-db-password',
        ]] as $answers) {
            $screen = QuickSetup::next($env, ['mode' => 'prepare', 'state' => $screen['state'], 'answers' => $answers]);
        }
        expect($screen['action'])->toBe('prepared')
            ->and($screen['configuration']['setup']['simulators'][0])->toMatchArray(['http_port' => 19140, 'console_port' => 19144])
            ->and($screen['configuration']['setup']['simulators'][0]['regions'][0]['port'])->toBe(19141);
    });

    test('keeps standalone login and HTTP together', function () {
        $result = QuickSetup::plan((new Machine())->environment, port_setup_answers(['type' => 'standalone']));
        expect($result['problems'])->toHaveKey('http_port');
    });

});
