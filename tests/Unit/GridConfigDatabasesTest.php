<?php

declare(strict_types=1);

/**
 * GridConfig::withDatabaseDefaults().
 */

use OpenSim\Config\GridConfig;

const DB_DEFAULT = 'Data Source=localhost;Database=grid;User ID=opensim;Password=secret;Old Guids=true;';
const DB_OTHER = 'Data Source=localhost;Database=profiles;User ID=opensim;Password=secret;Old Guids=true;';

/** A config with a Robust file and two simulators. */
function databases_config(): GridConfig
{
    return (new GridConfig([
        'Robust' => [
            'DatabaseService' => ['StorageProvider' => 'OpenSim.Data.MySQL.dll', 'ConnectionString' => DB_DEFAULT],
            'AssetService' => ['LocalServiceModule' => 'OpenSim.Services.AssetService.dll:AssetService'],
            'UserProfilesService' => ['ConnectionString' => DB_OTHER],
            'LoginService' => ['WelcomeMessage' => 'Welcome'],
        ],
        'simulators' => [
            'one' => ['DatabaseService' => ['ConnectionString' => DB_OTHER]],
            'two' => ['DatabaseService' => ['ConnectionString' => DB_DEFAULT, 'EstateConnectionString' => DB_OTHER, 'StorageProvider' => 'OpenSim.Data.MySQL.dll']],
            'three' => ['Startup' => ['x' => 'y']],
        ],
    ]))->withDatabaseDefaults();
}

describe('database settings of a config', function () {
    test('are those of [DatabaseService] for a service of Robust that gives none', function () {
        $config = databases_config();

        expect($config->get('Robust.AssetService.ConnectionString'))->toBe(DB_DEFAULT)
            ->and($config->get('Robust.AssetService.StorageProvider'))->toBe('OpenSim.Data.MySQL.dll')
            ->and($config->get('Robust.AssetService.LocalServiceModule'))->toBe('OpenSim.Services.AssetService.dll:AssetService');
    });

    test('are the own of a service that gives them, and the StorageProvider of [DatabaseService] for the rest', function () {
        $config = databases_config();

        expect($config->get('Robust.UserProfilesService.ConnectionString'))->toBe(DB_OTHER)
            ->and($config->get('Robust.UserProfilesService.StorageProvider'))->toBe('OpenSim.Data.MySQL.dll');
    });

    test('are not made for a section that is not a database, nor for a section the file does not have', function () {
        $config = databases_config();

        expect($config->get('Robust.LoginService'))->toBe(['WelcomeMessage' => 'Welcome'])
            ->and($config->get('Robust.UserAccountService'))->toBeNull();
    });

    test('are for a simulator the connection of the regions and of the estates, the estates taking EstateConnectionString first', function () {
        $config = databases_config();

        expect($config->get('simulators.one.DatabaseService.EstateConnectionString'))->toBe(DB_OTHER)
            ->and($config->get('simulators.one.SimulationDataStore.ConnectionString'))->toBe(DB_OTHER)
            ->and($config->get('simulators.one.EstateDataStore.ConnectionString'))->toBe(DB_OTHER)
            ->and($config->get('simulators.two.SimulationDataStore.ConnectionString'))->toBe(DB_DEFAULT)
            ->and($config->get('simulators.two.EstateDataStore.ConnectionString'))->toBe(DB_OTHER)
            ->and($config->get('simulators.two.EstateDataStore.StorageProvider'))->toBe('OpenSim.Data.MySQL.dll');
    });

    test('are not invented for a simulator that has no database', function () {
        expect(databases_config()->get('simulators.three'))->toBe(['Startup' => ['x' => 'y']]);
    });

    test('leave the config they are made from as it is', function () {
        $raw = new GridConfig(['Robust' => ['DatabaseService' => ['ConnectionString' => DB_DEFAULT], 'AssetService' => []]]);
        $raw->withDatabaseDefaults();

        expect($raw->get('Robust.AssetService'))->toBe([]);
    });
});
