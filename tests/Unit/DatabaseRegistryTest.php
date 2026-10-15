<?php

declare(strict_types=1);

/**
 * The databases of a grid, from its config: where each is set, in which order, and what it falls back to.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Data\Database;

/** A grid folder: Robust with an asset service of its own, two simulators, the MoneyServer and the helpers. */
function registry_grid(array $files = []): GridConfig
{
    $dir = test_tmp() . '/registry-' . bin2hex(random_bytes(4));
    mkdir("$dir/sims", 0o755, true);
    $db = static fn(string $name, string $user = 'os', string $host = 'localhost') => "Data Source=$host;Database=$name;User ID=$user;Password=pw_$name;Old Guids=true;";
    $files += [
        'Robust.HG.ini' => "[DatabaseService]\n ConnectionString = \"" . $db('grid_robust') . "\"\n[AssetService]\n ConnectionString = \"" . $db('grid_assets', 'assets', 'db.example.org:3307') . "\"\n[UserAccountService]\n ConnectionString = \"" . $db('grid_accounts') . "\"\n",
        'sims/alpha.ini' => "[DatabaseService]\n ConnectionString = \"" . $db('alpha') . "\"\n[EstateDataStore]\n ConnectionString = \"" . $db('alpha_estate') . "\"\n",
        'sims/beta.ini' => "[DatabaseService]\n ConnectionString = \"" . $db('beta') . "\"\n EstateConnectionString = \"" . $db('beta_estates') . "\"\n",
        'sims/gamma.ini' => "[DatabaseService]\n ConnectionString = \"" . $db('gamma') . "\"\n",
    ];
    foreach ($files as $name => $text) {
        if ($text !== null) {
            file_put_contents("$dir/$name", $text);
        }
    }

    return GridConfig::fromFolder($dir, ['Helpers' => 'Helpers.ini']);
}

describe('the databases of the grid', function () {
    test('are the default one of Robust, or the one the service has in its section', function () {
        $config = registry_grid();

        expect(Database::Robust->connection($config)->database)->toBe('grid_robust')
            ->and(Database::Assets->connection($config)->database)->toBe('grid_assets')
            ->and(Database::Assets->connection($config)->host)->toBe('db.example.org')
            ->and(Database::Assets->connection($config)->port)->toBe(3307)
            ->and(Database::Profiles->connection($config)->database)->toBe('grid_robust')
            ->and(Database::Offline->connection($config)->database)->toBe('grid_robust')
            ->and(Database::Search->connection($config)->database)->toBe('grid_robust');
    });

    test('of the services are the ones of their section, else the one of Robust', function () {
        $config = registry_grid();

        expect(Database::service($config, 'UserAccountService')->database)->toBe('grid_accounts')
            ->and(Database::service($config, 'GridUserService')->database)->toBe('grid_robust');
    });

    test('offline messages, search and profiles take what their own section says', function () {
        $config = registry_grid([
            'Robust.HG.ini' => "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=r;User ID=u;Password=p;\"\n[Messaging]\n ConnectionString = \"Data Source=localhost;Database=offline;User ID=u;Password=p;\"\n[UserProfilesService]\n ConnectionString = \"Data Source=localhost;Database=profiles;User ID=u;Password=p;\"\n",
            'Helpers.ini' => "[Helpers]\n SearchConnectionString = \"Data Source=localhost;Database=search;User ID=u;Password=p;\"\n",
        ]);

        expect(Database::Offline->connection($config)->database)->toBe('offline')
            ->and(Database::Profiles->connection($config)->database)->toBe('profiles')
            ->and(Database::Search->connection($config)->database)->toBe('search');
    });

    test('are in Helpers.ini when the host has a reduced copy of the Robust config', function () {
        $config = registry_grid([
            'Robust.HG.ini' => null,
            'Helpers.ini' => "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=reduced;User ID=u;Password=p;\"\n[Messaging]\n ConnectionString = \"Data Source=localhost;Database=reduced_offline;User ID=u;Password=p;\"\n",
        ]);

        expect(Database::Robust->connection($config)->database)->toBe('reduced')
            ->and(Database::Offline->connection($config)->database)->toBe('reduced_offline')
            ->and(Database::service($config, 'GridUserService')->database)->toBe('reduced');
    });

    test('are not there when the grid says nothing', function () {
        $config = GridConfig::fromFolder(test_tmp() . '/nowhere-' . bin2hex(random_bytes(4)));

        expect(Database::Robust->connection($config))->toBeNull()
            ->and(Database::Assets->connection($config))->toBeNull()
            ->and(Database::Search->pdo($config))->toBeNull();
    });
});

describe('the databases of a simulator', function () {
    test('are its own, the estates falling back to the estate connection, then to the simulator', function () {
        $config = registry_grid();

        expect(Database::Simulator->connection($config, 'alpha')->database)->toBe('alpha')
            ->and(Database::Estate->connection($config, 'alpha')->database)->toBe('alpha_estate')
            ->and(Database::Estate->connection($config, 'beta')->database)->toBe('beta_estates')
            ->and(Database::Estate->connection($config, 'gamma')->database)->toBe('gamma');
    });

    test('are nothing without the name of a simulator, or for a simulator that is not there', function () {
        $config = registry_grid();

        expect(Database::Simulator->connection($config))->toBeNull()
            ->and(Database::Estate->connection($config, 'nobody'))->toBeNull();
    });

    test('are the ones of OpenSim.ini of the grid when the simulator does not say it', function () {
        $config = registry_grid([
            'OpenSim.ini' => "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=shared;User ID=u;Password=p;\"\n",
            'sims/alpha.ini' => "[Startup]\n region_info_source = filesystem\n",
        ]);

        expect(Database::Simulator->connection($config, 'alpha')->database)->toBe('shared');
    });
});

describe('the economy', function () {
    $module = static fn(string $name) => "[Economy]\n economymodule = $name\n";

    test('has no database with the module OpenSimulator has by default, or none', function () use ($module) {
        expect(Database::Economy->connection(registry_grid(['sims/alpha.ini' => $module('BetaGridLikeMoneyModule')]), 'alpha'))->toBeNull()
            ->and(Database::Economy->connection(registry_grid(), 'alpha'))->toBeNull();
    });

    test('is the one of the MoneyServer, in the parts of its [MySql]', function () use ($module) {
        $config = registry_grid([
            'sims/alpha.ini' => $module('DTLNSLMoneyModule'),
            'MoneyServer.ini' => "[MySql]\n hostname = money.example.org\n database = grid_money\n username = money\n password = secret\n port = 3307\n",
        ]);
        $connection = Database::Economy->connection($config);

        expect($connection->database)->toBe('grid_money')
            ->and($connection->host)->toBe('money.example.org')
            ->and($connection->port)->toBe(3307)
            ->and($connection->user)->toBe('money')
            ->and($connection->password)->toBe('secret');
    });

    test('is not a database the MoneyServer.ini of the distribution has not been edited for', function () use ($module) {
        $config = registry_grid([
            'sims/alpha.ini' => $module('DTLNSLMoneyModule'),
            'MoneyServer.ini' => "[MySql]\n hostname = localhost\n database = Database_name\n username = Database_user\n password = Database_password\n",
        ]);

        expect(Database::Economy->connection($config))->toBeNull();
    });

    test('of Gloebit is its own storage, else the default one of the simulator', function () use ($module) {
        $config = registry_grid([
            'sims/alpha.ini' => $module('Gloebit') . "[Gloebit]\n GLBSpecificConnectionString = \"Data Source=localhost;Database=gloebit;User ID=u;Password=p;\"\n[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=alpha;User ID=u;Password=p;\"\n",
            'sims/beta.ini' => $module('Gloebit') . "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=beta;User ID=u;Password=p;\"\n",
        ]);

        expect(Database::Economy->connection($config, 'alpha')->database)->toBe('gloebit')
            ->and(Database::Economy->connection($config, 'beta')->database)->toBe('beta');
    });
});

describe('the list of the databases', function () {
    test('has the ones of the grid once and the ones of each simulator', function () {
        $known = Database::known(registry_grid());
        $names = array_map(static fn(array $row): string => $row['database']->value . ($row['simulator'] !== null ? ':' . $row['simulator'] : ''), $known);

        expect($names)->toBe([
            'robust', 'assets', 'profiles', 'offline', 'simulator:alpha', 'simulator:beta', 'simulator:gamma',
            'estate:alpha', 'estate:beta', 'estate:gamma', 'economy', 'search',
        ]);
    });

    test('say what they hold', function () {
        expect(Database::Simulator->tables())->toContain('land', 'prims')
            ->and(Database::Robust->tables())->toContain('regions', 'UserAccounts')
            ->and(Database::Estate->tables())->toContain('estate_settings')
            ->and(Database::Offline->tables())->toBe(['im_offline'])
            ->and(Database::Simulator->scope())->toBe(Database::PER_SIMULATOR)
            ->and(Database::Robust->scope())->toBe(Database::GRID)
            ->and(Database::Assets->settings())->toBe(['Robust.AssetService.ConnectionString', 'Robust.DatabaseService.ConnectionString']);
    });

    test('open on OSPDO from the credentials of their connection', function () {
        $credentials = Database::Assets->connection(registry_grid())->credentials();

        expect($credentials)->toBe(['host' => 'db.example.org', 'port' => 3307, 'name' => 'grid_assets', 'user' => 'assets', 'pass' => 'pw_grid_assets']);
    });
});

describe('the ban', function () {
    test('reads the databases of Robust, of the simulators and of their estates, each once', function () {
        $machine = new OpenSim\Testing\Machine();
        $db = static fn(string $name) => "ConnectionString = \"Data Source=localhost;Database=$name;User ID=os;Password=pw;\"\n";
        $machine->write($machine->grid . '/Robust.HG.ini', "[DatabaseService]\n " . $db('demo_robust'));
        $machine->write($machine->grid . '/sims/alpha.ini', "[DatabaseService]\n " . $db('alpha') . "[EstateDataStore]\n " . $db('shared_estate'));
        $machine->write($machine->grid . '/sims/beta.ini', "[DatabaseService]\n " . $db('beta') . "[EstateDataStore]\n " . $db('shared_estate'));

        [$robust, $others] = OpenSim\Moderation\UserBan::databases(OpenSim\Models\Grid::find($machine->environment, 'demo'));

        expect($robust->database)->toBe('demo_robust')
            ->and(array_map(static fn($c) => $c->database, $others))->toBe(['alpha', 'shared_estate', 'beta']);
    });
});
