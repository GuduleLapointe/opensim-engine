<?php

declare(strict_types=1);

/**
 * The regions of a grid through the model: the flags with the values of OpenSimulator, the list (search, sort, filters, pages),
 * one region, and the names of the flags in the config of Robust. The database is in memory (SQLite) with the tables of OpenSimulator.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Models\ChannelUnavailable;
use OpenSim\Models\Region;

/** A grid folder whose Robust says its web address. */
function region_config(): GridConfig
{
    $dir = test_tmp() . '/regions-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n PublicPort = \"8002\"\n[Hypergrid]\n GatekeeperURI = \"\${Const|BaseURL}:\${Const|PublicPort}\"\n[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;\"\n");

    return GridConfig::fromFolder($dir);
}

/** Three regions of the grid and a link to a region of another grid, and their owner. */
function region_database(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec('CREATE TABLE regions (uuid VARCHAR(36) PRIMARY KEY, regionHandle BIGINT, regionName VARCHAR(128), locX INT, locY INT, sizeX INT DEFAULT 0, sizeY INT DEFAULT 0, serverIP VARCHAR(64), serverPort INT, serverURI VARCHAR(255), flags INT DEFAULT 0, last_seen INT DEFAULT 0, PrincipalID CHAR(36), access INT DEFAULT 21, ScopeID CHAR(36), regionMapTexture VARCHAR(36))');
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64))');
    $pdo->exec("INSERT INTO UserAccounts VALUES ('11111111-1111-1111-1111-111111111111', 'Ada', 'Love')");
    $pdo->exec("INSERT INTO regions (uuid, regionHandle, regionName, locX, locY, sizeX, sizeY, serverIP, serverPort, serverURI, flags, last_seen, PrincipalID) VALUES
        ('aaaaaaaa-0000-0000-0000-000000000001', 1, 'Welcome', 256000, 256000, 256, 256, '10.0.0.1', 9001, 'http://10.0.0.1:9000/', 1029, 1700000000, '11111111-1111-1111-1111-111111111111'),
        ('aaaaaaaa-0000-0000-0000-000000000002', 2, 'Garden', 256256, 256000, 512, 512, '10.0.0.1', 9002, 'http://10.0.0.1:9000/', 22, 1600000000, '11111111-1111-1111-1111-111111111111'),
        ('aaaaaaaa-0000-0000-0000-000000000003', 3, 'Quiet Harbour', 255744, 256000, 0, 0, '10.0.0.2', 9011, 'http://10.0.0.2:9010/', 1040, 0, '00000000-0000-0000-0000-000000000000'),
        ('aaaaaaaa-0000-0000-0000-000000000004', 4, 'Far Away', 0, 0, 256, 256, '', 0, 'http://other.example.org:8002/', 512, 5, '00000000-0000-0000-0000-000000000000')");

    return $pdo;
}

afterEach(fn() => Connections::using(null));

describe('The flags of a region', function () {
    test('have the values of OpenSimulator', function () {
        expect([
            Region::DEFAULT_REGION, Region::FALLBACK_REGION, Region::REGION_ONLINE, Region::NO_DIRECT_LOGIN, Region::PERSISTENT, Region::LOCKED_OUT,
            Region::NO_MOVE, Region::RESERVATION, Region::AUTHENTICATE, Region::HYPERLINK, Region::DEFAULT_HGREGION,
        ])->toBe([1, 2, 4, 8, 16, 32, 64, 128, 256, 512, 1024]);
    });

    test('have the name the config of Robust gives them, and a label to show', function () {
        expect(Region::flagName(Region::DEFAULT_HGREGION))->toBe('DefaultHGRegion')
            ->and(Region::flagName(Region::NO_DIRECT_LOGIN))->toBe('NoDirectLogin')
            ->and(Region::flagValue('FallbackRegion'))->toBe(2)
            ->and(Region::flagValue('Nonsense'))->toBe(0)
            ->and(Region::label(Region::DEFAULT_REGION))->toBe('Default Region')
            ->and(Region::flagLabels(1029))->toBe([1 => 'Default Region', 1024 => 'Default HG Region', 4 => 'Online'])
            ->and(fn() => Region::flagName(3))->toThrow(InvalidArgumentException::class)
            ->and(fn() => Region::flagName(Region::REGION_ONLINE))->toThrow(InvalidArgumentException::class);
    });

    test('have names in the config of Robust for the ones a region can be given', function () {
        expect(Region::flagNames(0))->toBe([])
            ->and(Region::flagNames(Region::FALLBACK_REGION | Region::PERSISTENT))->toBe(['FallbackRegion', 'Persistent'])
            ->and(Region::flagNames(1029))->toBe(['DefaultRegion', 'DefaultHGRegion'])
            ->and(Region::flagNames(Region::REGION_ONLINE | Region::HYPERLINK))->toBe([])
            ->and(Region::flagNames(2047))->toBe([
                'DefaultRegion', 'DefaultHGRegion', 'FallbackRegion', 'NoDirectLogin', 'Persistent', 'LockedOut', 'Reservation', 'NoMove',
                'Authenticate',
            ]);
    });
});

describe('The regions', function () {
    test('are listed without the links to other grids, with their place, their size and their owner', function () {
        Connections::using(static fn() => region_database());
        $list = Region::all(region_config());

        expect(array_column($list->array(), 'name'))->toBe(['Garden', 'Quiet Harbour', 'Welcome'])
            ->and($list->total())->toBe(3)
            ->and($list->applied())->toBe(['hyperlink' => 'no']);

        $welcome = $list->last()->detail();
        expect($welcome['uuid'])->toBe('aaaaaaaa-0000-0000-0000-000000000001')
            ->and($welcome['slug'])->toBe('welcome')
            ->and($welcome['uri'])->toBe('play.example.org:8002:Welcome')
            ->and([$welcome['x'], $welcome['y']])->toBe([1000, 1000])
            ->and($welcome['location'])->toBe('1000,1000')
            ->and($welcome['size'])->toBe('256x256')
            ->and([$welcome['width'], $welcome['height']])->toBe([256, 256])
            ->and($welcome['port'])->toBe(9001)
            ->and($welcome['owner'])->toBe('11111111-1111-1111-1111-111111111111')
            ->and($welcome['ownerName'])->toBe('Ada Love')
            ->and($welcome['isDefault'])->toBeTrue()
            ->and($welcome['online'])->toBeTrue()
            ->and($welcome['roles'])->toBe(['DefaultRegion', 'DefaultHGRegion'])
            ->and($welcome['flagLabels'])->toBe(['Default Region', 'Default HG Region', 'Online'])
            ->and($welcome['lastSeen'])->toBe(1700000000);
    });

    test('know their flags, their size when the table does not say it, and nobody as an owner', function () {
        Connections::using(static fn() => region_database());
        [$garden, $harbour, $welcome] = Region::all(region_config())->items();

        expect($garden->roles())->toBe(['FallbackRegion', 'Persistent'])->and($welcome->roles())->toBe(['DefaultRegion', 'DefaultHGRegion'])
            ->and($garden->width())->toBe(512)->and($garden->isFallback())->toBeTrue()->and($garden->isDefault())->toBeFalse()
            ->and($garden->isOnline())->toBeTrue()->and($garden->has(Region::PERSISTENT))->toBeTrue()
            ->and($harbour->width())->toBe(256)->and($harbour->owner())->toBeNull()->and($harbour->isOnline())->toBeFalse()
            ->and($harbour->isDefault())->toBeTrue()->and($harbour->has(Region::PERSISTENT | Region::DEFAULT_HGREGION))->toBeTrue()
            ->and($harbour->lastSeen())->toBeNull()
            ->and($welcome->lastSeen()->getTimestamp())->toBe(1700000000);
    });

    test('are filtered, a link to another grid only when asked', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();
        $names = static fn(array $filters) => array_column(Region::all($config, ['filters' => $filters])->array(), 'name');

        expect($names(['online' => 'yes']))->toBe(['Garden', 'Welcome'])
            ->and($names(['online' => 'no']))->toBe(['Quiet Harbour'])
            ->and($names(['default' => 'yes']))->toBe(['Quiet Harbour', 'Welcome'])
            ->and($names(['fallback' => 'yes']))->toBe(['Garden'])
            ->and($names(['hyperlink' => 'yes']))->toBe(['Far Away'])
            ->and($names(['hyperlink' => 'bogus']))->toBe(['Garden', 'Quiet Harbour', 'Welcome']);
    });

    test('are searched, sorted and paged', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();
        $names = static fn(array $options) => array_column(Region::all($config, $options)->array(), 'name');

        expect($names(['search' => 'har']))->toBe(['Quiet Harbour'])
            ->and($names(['search' => 'quiet har']))->toBe(['Quiet Harbour'])
            ->and($names(['sort' => 'name desc']))->toBe(['Welcome', 'Quiet Harbour', 'Garden'])
            ->and($names(['sort' => 'location']))->toBe(['Quiet Harbour', 'Welcome', 'Garden'])
            ->and($names(['sort' => 'lastSeen desc']))->toBe(['Welcome', 'Garden', 'Quiet Harbour'])
            ->and($names(['sort' => 'size desc']))->toBe(['Garden', 'Welcome', 'Quiet Harbour'])
            ->and($names(['sort' => '1; DROP TABLE regions']))->toBe(['Garden', 'Quiet Harbour', 'Welcome'])
            ->and($names(['perPage' => 2, 'page' => 2]))->toBe(['Welcome'])
            ->and(Region::all($config, ['perPage' => 2])->total())->toBe(3)
            ->and(Region::all($config, ['perPage' => 2])->pages())->toBe(2);
    });

    test('can be asked by their UUIDs, and the grid says which of some it knows', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();
        $names = static fn(array $options) => array_column(Region::all($config, $options)->array(), 'name');

        expect($names(['uuids' => ['AAAAAAAA-0000-0000-0000-000000000002', 'aaaaaaaa-0000-0000-0000-000000000001', 'cccccccc-0000-0000-0000-000000000000']]))->toBe(['Garden', 'Welcome'])
            ->and($names(['uuids' => []]))->toBe([])
            ->and(Region::all($config, ['uuids' => ['aaaaaaaa-0000-0000-0000-000000000002'], 'perPage' => 5])->total())->toBe(1)
            ->and(array_values(array_diff(Region::known($config, ['aaaaaaaa-0000-0000-0000-000000000004', 'cccccccc-0000-0000-0000-000000000000', 'AAAAAAAA-0000-0000-0000-000000000001']), ['aaaaaaaa-0000-0000-0000-000000000001'])))->toBe(['aaaaaaaa-0000-0000-0000-000000000004'])
            ->and(Region::known($config, ['cccccccc-0000-0000-0000-000000000000']))->toBe([]);
    });

    test('are found by their UUID or their name', function () {
        Connections::using(static fn() => region_database());
        $config = region_config();

        expect(Region::find($config, 'aaaaaaaa-0000-0000-0000-000000000002')->name())->toBe('Garden')
            ->and(Region::find($config, 'Quiet Harbour')->uuid())->toBe('aaaaaaaa-0000-0000-0000-000000000003')
            ->and(Region::find($config, 'Far Away')->isHyperlink())->toBeTrue()
            ->and(Region::find($config, 'Nowhere'))->toBeNull();
    });

    test('are not changed by any channel yet', function () {
        Connections::using(static fn() => region_database());
        $welcome = Region::find(region_config(), 'Welcome');

        expect($welcome->channels())->toBe([])
            ->and(fn() => $welcome->disable())->toThrow(ChannelUnavailable::class)
            ->and(fn() => $welcome->update(['name' => 'Home']))->toThrow(ChannelUnavailable::class);
    });

    test('cannot be listed when the database cannot be reached', function () {
        Connections::using(static fn() => null);

        expect(fn() => Region::all(region_config()))->toThrow(ChannelUnavailable::class)
            ->and(Region::find(region_config(), 'Welcome'))->toBeNull();
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
