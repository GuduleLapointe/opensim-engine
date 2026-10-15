<?php

declare(strict_types=1);

/**
 * The parcels of a grid through the model: the land table of each simulator, the squares of the bitmap, the flags, the list and the
 * parcel that holds a point. The databases are in memory (SQLite) with the tables of OpenSimulator.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Models\ChannelUnavailable;
use OpenSim\Models\Parcel;

/** A grid with two simulators, each with its own database. */
function parcel_config(bool $shared = false): GridConfig
{
    $dir = test_tmp() . '/parcels-' . bin2hex(random_bytes(4));
    mkdir("$dir/sims", 0o755, true);
    $db = static fn(string $name) => "ConnectionString = \"Data Source=localhost;Database=$name;User ID=os;Password=pw;\"\n";
    file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n PublicPort = \"8002\"\n[DatabaseService]\n " . $db('robust'));
    file_put_contents("$dir/sims/alpha.ini", "[DatabaseService]\n " . $db($shared ? 'shared' : 'alpha'));
    file_put_contents("$dir/sims/beta.ini", "[DatabaseService]\n " . $db($shared ? 'shared' : 'beta'));

    return GridConfig::fromFolder($dir);
}

/** A bitmap of a region of 256 meters (64 x 64 squares) with these squares set: [column, row]. */
function parcel_bitmap(array $squares, int $width = 64, int $length = 512): string
{
    $bytes = array_fill(0, $length, 0);
    foreach ($squares as [$column, $row]) {
        $n = $row * $width + $column;
        $bytes[$n >> 3] |= 1 << ($n & 7);
    }

    return implode('', array_map('chr', $bytes));
}

/** The tables of the land, the regions and the accounts. */
function parcel_database(array $parcels): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec('CREATE TABLE land (UUID VARCHAR(255) PRIMARY KEY, RegionUUID VARCHAR(255), LocalLandID INT, Bitmap BLOB, Name VARCHAR(255), Description VARCHAR(255), OwnerUUID VARCHAR(255), IsGroupOwned INT, GroupUUID VARCHAR(255), Area INT, Category INT, ClaimDate INT, SalePrice INT, LandFlags INT, LandingType INT, SnapshotUUID VARCHAR(255), UserLocationX FLOAT, UserLocationY FLOAT, UserLocationZ FLOAT, Dwell INT, MusicURL VARCHAR(255), MediaURL VARCHAR(255))');
    $pdo->exec('CREATE TABLE regions (uuid VARCHAR(36), regionHandle BIGINT, regionName VARCHAR(128), sizeX INT, sizeY INT, locX INT, locY INT, serverIP VARCHAR(64), serverPort INT, serverURI VARCHAR(255), flags INT, last_seen INT, PrincipalID CHAR(36), access INT, ScopeID CHAR(36), regionMapTexture VARCHAR(36))');
    $pdo->exec("INSERT INTO regions (uuid, regionName, sizeX, sizeY) VALUES ('aaaaaaaa-0000-0000-0000-000000000001', 'Welcome', 256, 256), ('bbbbbbbb-0000-0000-0000-000000000001', 'Harbour', 256, 256)");
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64))');
    $pdo->exec("INSERT INTO UserAccounts VALUES ('11111111-1111-1111-1111-111111111111', 'Ada', 'Love')");
    $insert = $pdo->prepare('INSERT INTO land VALUES (:UUID, :RegionUUID, :LocalLandID, :Bitmap, :Name, :Description, :OwnerUUID, :IsGroupOwned, :GroupUUID, :Area, 0, :ClaimDate, :SalePrice, :LandFlags, 0, NULL, :X, :Y, :Z, :Dwell, NULL, NULL)');
    foreach ($parcels as $parcel) {
        $insert->execute($parcel + ['IsGroupOwned' => 0, 'GroupUUID' => '00000000-0000-0000-0000-000000000000', 'ClaimDate' => 1700000000, 'SalePrice' => 0, 'LandFlags' => 0, 'X' => 128.0, 'Y' => 128.0, 'Z' => 22.0, 'Dwell' => 0, 'Description' => '']);
    }

    return $pdo;
}

/** Two parcels in Welcome (a big one and a small one on the squares 0,0 and 1,0), one in Harbour. */
function parcel_databases(): array
{
    $ada = '11111111-1111-1111-1111-111111111111';
    $alpha = parcel_database([
        ['UUID' => 'p1', 'RegionUUID' => 'aaaaaaaa-0000-0000-0000-000000000001', 'LocalLandID' => 1, 'Bitmap' => parcel_bitmap([[0, 0], [2, 0], [2, 1]]), 'Name' => 'The Garden', 'Description' => 'roses and a pond', 'OwnerUUID' => $ada, 'Area' => 48, 'LandFlags' => Parcel::SHOW_DIRECTORY | Parcel::ALLOW_FLY, 'Dwell' => 30],
        ['UUID' => 'p2', 'RegionUUID' => 'aaaaaaaa-0000-0000-0000-000000000001', 'LocalLandID' => 2, 'Bitmap' => parcel_bitmap([[1, 0]]), 'Name' => 'Kiosk', 'OwnerUUID' => '22222222-2222-2222-2222-222222222222', 'IsGroupOwned' => 1, 'GroupUUID' => '33333333-3333-3333-3333-333333333333', 'Area' => 16, 'LandFlags' => Parcel::FOR_SALE, 'SalePrice' => 500, 'Dwell' => 5],
    ]);
    $beta = parcel_database([
        ['UUID' => 'p3', 'RegionUUID' => 'bbbbbbbb-0000-0000-0000-000000000001', 'LocalLandID' => 1, 'Bitmap' => parcel_bitmap([]), 'Name' => 'Harbour Front', 'OwnerUUID' => $ada, 'Area' => 65536, 'LandFlags' => Parcel::SHOW_DIRECTORY, 'Dwell' => 80],
    ]);
    $alpha->exec('DELETE FROM regions WHERE uuid LIKE "bbbb%"');

    return [$alpha, $beta];
}

function parcels_in(array $databases, ?OSPDO $robust = null): void
{
    [$alpha, $beta] = $databases;
    Connections::using(static fn(Database|string $what, ?string $simulator) => match (true) {
        $what === Database::Simulator => $simulator === 'beta' ? $beta : $alpha,
        default => $alpha,
    });
}

afterEach(fn() => Connections::using(null));

describe('The flags of a parcel', function () {
    test('have the values of LSL, and of the protocol for FOR_SALE and SHOW_DIRECTORY', function () {
        expect([Parcel::ALLOW_FLY, Parcel::ALLOW_SCRIPTS, Parcel::FOR_SALE, Parcel::ALLOW_LANDMARK, Parcel::ALLOW_TERRAFORM, Parcel::ALLOW_DAMAGE, Parcel::ALLOW_CREATE_OBJECTS])->toBe([1, 2, 4, 8, 16, 32, 64])
            ->and([Parcel::USE_ACCESS_GROUP, Parcel::USE_ACCESS_LIST, Parcel::USE_BAN_LIST, Parcel::USE_LAND_PASS_LIST, Parcel::SHOW_DIRECTORY, Parcel::LOCAL_SOUND_ONLY])->toBe([256, 512, 1024, 2048, 4096, 32768])
            ->and([Parcel::RESTRICT_PUSHOBJECT, Parcel::ALLOW_GROUP_SCRIPTS, Parcel::ALLOW_CREATE_GROUP_OBJECTS, Parcel::ALLOW_ALL_OBJECT_ENTRY, Parcel::ALLOW_GROUP_OBJECT_ENTRY])->toBe([0x200000, 0x2000000, 0x4000000, 0x8000000, 0x10000000])
            ->and(Parcel::flagLabels(Parcel::FOR_SALE | Parcel::SHOW_DIRECTORY))->toBe([4 => 'For sale', 4096 => 'In search']);
    });
});

describe('The parcels', function () {
    test('are listed from the land of every simulator, with their region and their owner', function () {
        parcels_in(parcel_databases());
        $list = Parcel::all(parcel_config());

        expect(array_column($list->array(), 'name'))->toBe(['Harbour Front', 'Kiosk', 'The Garden'])
            ->and($list->total())->toBe(3);

        $garden = $list->last()->detail();
        expect($garden['uuid'])->toBe('p1')
            ->and($garden['slug'])->toBe('the-garden')
            ->and($garden['regionName'])->toBe('Welcome')
            ->and($garden['uri'])->toBe('play.example.org:8002:Welcome/128/128/22')
            ->and($garden['owner'])->toBe('11111111-1111-1111-1111-111111111111')
            ->and($garden['ownerName'])->toBe('Ada Love')
            ->and($garden['area'])->toBe(48)
            ->and($garden['dwell'])->toBe(30)
            ->and($garden['shownInSearch'])->toBeTrue()
            ->and($garden['forSale'])->toBeFalse();
    });

    test('know who owns them: a user or a group', function () {
        parcels_in(parcel_databases());
        $kiosk = Parcel::find(parcel_config(), 'p2');

        expect($kiosk->isGroupOwned())->toBeTrue()->and($kiosk->owner())->toBeNull()
            ->and($kiosk->group())->toBe('33333333-3333-3333-3333-333333333333')
            ->and($kiosk->isForSale())->toBeTrue()->and($kiosk->detail()['price'])->toBe(500);
    });

    test('are searched, sorted, filtered and paged', function () {
        parcels_in(parcel_databases());
        $config = parcel_config();
        $names = static fn(array $options) => array_column(Parcel::all($config, $options)->array(), 'name');

        expect($names(['search' => 'pond']))->toBe(['The Garden'])
            ->and($names(['search' => 'har front']))->toBe(['Harbour Front'])
            ->and($names(['sort' => 'dwell desc']))->toBe(['Harbour Front', 'The Garden', 'Kiosk'])
            ->and($names(['sort' => 'area']))->toBe(['Kiosk', 'The Garden', 'Harbour Front'])
            ->and($names(['sort' => 'nonsense']))->toBe(['Harbour Front', 'Kiosk', 'The Garden'])
            ->and($names(['filters' => ['shown' => 'yes']]))->toBe(['Harbour Front', 'The Garden'])
            ->and($names(['filters' => ['forSale' => 'yes']]))->toBe(['Kiosk'])
            ->and($names(['filters' => ['region' => 'aaaaaaaa-0000-0000-0000-000000000001']]))->toBe(['Kiosk', 'The Garden'])
            ->and($names(['filters' => ['owner' => '11111111-1111-1111-1111-111111111111']]))->toBe(['Harbour Front', 'The Garden'])
            ->and($names(['filters' => ['owner' => 'not a uuid']]))->toBe(['Harbour Front', 'Kiosk', 'The Garden'])
            ->and($names(['perPage' => 2, 'page' => 2]))->toBe(['The Garden'])
            ->and(Parcel::all($config, ['perPage' => 2])->total())->toBe(3);
    });

    test('are read once from a database that simulators share', function () {
        [$alpha, $beta] = parcel_databases();
        $asked = 0;
        Connections::using(static function (Database|string $what) use ($alpha, &$asked) {
            $asked += $what === Database::Simulator ? 1 : 0;

            return $alpha;
        });

        expect(Parcel::all(parcel_config(shared: true))->total())->toBe(2)->and($asked)->toBe(1);
    });

    test('cannot be listed when no simulator database can be reached', function () {
        Connections::using(static fn() => null);

        expect(fn() => Parcel::all(parcel_config()))->toThrow(ChannelUnavailable::class)
            ->and(Parcel::find(parcel_config(), 'p1'))->toBeNull();
    });
});

describe('The bitmap of a parcel', function () {
    test('says which square holds a point, 4 meters a square, the south-west corner first', function () {
        parcels_in(parcel_databases());
        $garden = Parcel::find(parcel_config(), 'p1');

        expect($garden->holds(0, 0))->toBeTrue()->and($garden->holds(3.9, 3.9))->toBeTrue()
            ->and($garden->holds(4, 0))->toBeFalse()->and($garden->holds(8, 0))->toBeTrue()->and($garden->holds(11, 5))->toBeTrue()
            ->and($garden->holds(8, 8))->toBeFalse()->and($garden->holds(-1, 0))->toBeFalse()->and($garden->holds(300, 0))->toBeFalse()
            ->and($garden->squares())->toBe(3);
    });

    test('is not loaded for a parcel of a list', function () {
        parcels_in(parcel_databases());

        expect(fn() => Parcel::all(parcel_config())->first()->holds(0, 0))->toThrow(LogicException::class);
    });

    test('finds the parcel of a region that holds a point', function () {
        parcels_in(parcel_databases());
        $config = parcel_config();
        $region = 'aaaaaaaa-0000-0000-0000-000000000001';

        expect(Parcel::at($config, $region, 5, 2)->name())->toBe('Kiosk')
            ->and(Parcel::at($config, $region, 1, 1)->name())->toBe('The Garden')
            ->and(Parcel::at($config, $region, 200, 200))->toBeNull()
            ->and(Parcel::at($config, 'cccccccc-0000-0000-0000-000000000000', 1, 1))->toBeNull();
    });

    test('of a region larger than 256 meters follows the width of the region', function () {
        $pdo = parcel_database([
            ['UUID' => 'big', 'RegionUUID' => 'aaaaaaaa-0000-0000-0000-000000000001', 'LocalLandID' => 1, 'Bitmap' => parcel_bitmap([[100, 1]], 128, 2048), 'Name' => 'Big', 'OwnerUUID' => '11111111-1111-1111-1111-111111111111', 'Area' => 16],
        ]);
        parcels_in([$pdo, $pdo]);
        $big = Parcel::find(parcel_config(), 'big');

        expect($big->holds(400, 4, 512))->toBeTrue()->and($big->holds(400, 4))->toBeTrue()->and($big->holds(404, 4, 512))->toBeFalse();
    });
});
