<?php

declare(strict_types=1);

/**
 * Each query goes to the database of its service: the one the service has in its own section, else [DatabaseService], as the core
 * chooses it. A grid with one database for all is the usual case, and the same code.
 */

use OpenSim\Config\ConnectionString;
use OpenSim\Data\Database;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\AccountImporter;
use OpenSim\Provisioning\AccountWriter;
use OpenSim\Provisioning\DatabaseService;
use OpenSim\Testing\Machine;

/** The connection string of a database called $name. */
function routing_db(string $name): string
{
    return "ConnectionString = \"Data Source=db.example.org;Database=$name;User ID=os;Password=pw;Old Guids=true;\"\n";
}

/** A machine whose Robust gives its services their own databases and whose simulators keep their own. */
function routing_machine(): Machine
{
    $machine = new Machine();
    $machine->write(
        $machine->grid . '/Robust.HG.ini',
        implode("\n", [
            "[DatabaseService]\n " . routing_db('robust'),
            "[AssetService]\n " . routing_db('assets'),
            "[UserProfilesService]\n " . routing_db('profiles'),
            "[Messaging]\n " . routing_db('offline'),
            "[UserAccountService]\n " . routing_db('accounts'),
            "[MuteListStore]\n " . routing_db('mutes'),
            "[ServiceList]\n AssetServiceConnector = \"8003/OpenSim.Server.Handlers.dll:AssetServiceConnector\"\n",
        ]),
    );
    $machine->write(
        $machine->grid . '/sims/alpha.ini',
        "[DatabaseService]\n " .
            routing_db('alpha') .
            " EstateConnectionString = \"Data Source=db.example.org;Database=alpha_estates;User ID=os;Password=pw;\"\n[SimulationDataStore]\n " .
            routing_db('alpha_regions'),
    );
    $machine->write(
        $machine->grid . '/sims/beta.ini',
        "[DatabaseService]\n " . routing_db('beta') . "[EstateDataStore]\n " . routing_db('beta_estates'),
    );

    return $machine;
}

describe('Robust', function () {
    test('gives each service the database of its section, else the default one of [DatabaseService]', function () {
        $robust = Grid::find(routing_machine()->environment, 'demo')->robust();

        expect($robust->database()->database)
            ->toBe('robust')
            ->and($robust->connection(Database::Assets)->database)
            ->toBe('assets')
            ->and($robust->connection(Database::Profiles)->database)
            ->toBe('profiles')
            ->and($robust->connection(Database::Offline)->database)
            ->toBe('offline')
            ->and($robust->connection('UserAccountService')->database)
            ->toBe('accounts')
            ->and($robust->connection('GridService')->database)
            ->toBe('robust')
            ->and($robust->connection('GridUserService')->database)
            ->toBe('robust')
            ->and($robust->connection('MuteListStore')->database)
            ->toBe('mutes');
    });

    test('has no database for a service of its own when the section is empty', function () {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/Robust.HG.ini',
            "[DatabaseService]\n " . routing_db('robust') . "[AssetService]\n ConnectionString = \"\"\n",
        );
        $robust = Grid::find($machine->environment, 'demo')->robust();

        expect($robust->connection(Database::Assets)->database)->toBe('robust');
    });
});

describe('Simulator', function () {
    test(
        'keeps its regions in SimulationDataStore, else DatabaseService, and its estates where the core looks first',
        function () {
            $grid = Grid::find(routing_machine()->environment, 'demo');
            $alpha = $grid->simulator('alpha');
            $beta = $grid->simulator('beta');

            expect($alpha->database()->database)
                ->toBe('alpha_regions')
                ->and($alpha->estateDatabase()->database)
                ->toBe('alpha_estates')
                ->and($beta->database()->database)
                ->toBe('beta')
                ->and($beta->estateDatabase()->database)
                ->toBe('beta_estates')
                ->and($alpha->detail())
                ->toMatchArray(['databaseName' => 'alpha_regions', 'estateDatabaseName' => 'alpha_estates']);
        },
    );
});

describe('Grid', function () {
    test('asks any of its databases, and makes the plan of one to ask questions of it', function () {
        $grid = Grid::find(routing_machine()->environment, 'demo');

        expect($grid->connection('UserAccountService')->database)
            ->toBe('accounts')
            ->and($grid->connection(Database::Assets)->database)
            ->toBe('assets')
            ->and($grid->connection(Database::Simulator, 'alpha')->database)
            ->toBe('alpha_regions')
            ->and($grid->databasePlan('UserAccountService')->dbName)
            ->toBe('accounts')
            ->and($grid->databasePlan('GridService')->dbName)
            ->toBe('robust')
            ->and($grid->databasePlan()->dbName)
            ->toBe('robust');
    });

    test('knows every database it keeps data in, once each, the search left out', function () {
        $grid = Grid::find(routing_machine()->environment, 'demo');
        $names = static fn(array $connections): array => array_map(
            static fn(ConnectionString $c): string => $c->database,
            $connections,
        );

        expect($names($grid->connections()))
            ->toBe([
                'robust',
                'assets',
                'profiles',
                'offline',
                'accounts',
                'mutes',
                'alpha_regions',
                'alpha_estates',
                'beta',
                'beta_estates',
            ])
            ->and($names($grid->connections('alpha')))
            ->toBe(['alpha_regions', 'alpha_estates']);
    });

    test('is one database for all when no service has its own', function () {
        $machine = new Machine();
        $machine->write($machine->grid . '/Robust.HG.ini', "[DatabaseService]\n " . routing_db('robust'));
        $grid = Grid::find($machine->environment, 'demo');

        expect($grid->connection('UserAccountService')->key())
            ->toBe($grid->connection(Database::Assets)->key())
            ->and(count($grid->connections()))
            ->toBe(1);
    });
});

describe('table', function () {
    test('is in the database of its service', function () {
        expect(Database::forTable('assets'))
            ->toBe(Database::Assets)
            ->and(Database::forTable('userprofile'))
            ->toBe(Database::Profiles)
            ->and(Database::forTable('im_offline'))
            ->toBe(Database::Offline)
            ->and(Database::forTable('UserAccounts'))
            ->toBe('UserAccountService')
            ->and(Database::forTable('inventoryitems'))
            ->toBe('InventoryService')
            ->and(Database::forTable('regions'))
            ->toBe('GridService')
            ->and(Database::forTable('os_groups_membership'))
            ->toBe('Groups')
            ->and(Database::forTable('nothing_here'))
            ->toBeNull();
    });
});
