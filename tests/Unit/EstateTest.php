<?php

declare(strict_types=1);

/**
 * The estates of a grid through the model: the grid's when its Robust serves them, else each simulator's, with their regions, their
 * managers, their ban list. The databases are in memory (SQLite) with the tables of OpenSimulator.
 */

use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Estate;

const ESTATE_OWNER = '11111111-1111-1111-1111-111111111111';
const ESTATE_BANNED = '99999999-9999-9999-9999-999999999999';

/** A database of estates, with the estates it is given: [number => name]. */
function estate_database(array $estates, string $owner = ESTATE_OWNER): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec(
        'CREATE TABLE estate_settings (EstateID INTEGER PRIMARY KEY, EstateName VARCHAR(64), EstateOwner CHAR(36), ParentEstateID INT DEFAULT 0, PublicAccess INT DEFAULT 1, DenyAnonymous INT DEFAULT 0, PricePerMeter INT DEFAULT 1, AllowEnviromentOverride INT DEFAULT 0)',
    );
    $pdo->exec('CREATE TABLE estate_map (RegionID CHAR(36) PRIMARY KEY, EstateID INT)');
    $pdo->exec('CREATE TABLE estate_managers (EstateID INT, uuid CHAR(36))');
    $pdo->exec('CREATE TABLE estate_users (EstateID INT, uuid CHAR(36))');
    $pdo->exec('CREATE TABLE estate_groups (EstateID INT, uuid CHAR(36))');
    $pdo->exec(
        'CREATE TABLE estateban (EstateID INT, bannedUUID CHAR(36), bannedIp VARCHAR(16), bannedIpHostMask VARCHAR(16), bannedNameMask VARCHAR(255) DEFAULT NULL)',
    );
    foreach ($estates as $number => $name) {
        $pdo->exec(
            "INSERT INTO estate_settings (EstateID, EstateName, EstateOwner) VALUES ($number, '$name', '$owner')",
        );
        $pdo->exec("INSERT INTO estate_managers VALUES ($number, '$owner')");
    }

    return $pdo;
}

/** The people: the owner of the estates. */
function estate_accounts(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64))');
    $pdo->exec("INSERT INTO UserAccounts VALUES ('" . ESTATE_OWNER . "', 'Ada', 'Love')");

    return $pdo;
}

/** The files of a grid whose simulators keep their estates, or whose Robust serves them. */
function estate_config(bool $central = false): GridConfig
{
    $dir = test_tmp() . '/estates-' . bin2hex(random_bytes(4));
    mkdir("$dir/sims", 0o755, true);
    $db = static fn(
        string $name,
    ) => "ConnectionString = \"Data Source=localhost;Database=$name;User ID=os;Password=pw;\"\n";
    file_put_contents(
        "$dir/Robust.HG.ini",
        "[DatabaseService]\n " . $db('robust') . ($central ? "[ServiceList]\n EstateDataService = \"x\"\n" : ''),
    );
    file_put_contents("$dir/sims/alpha.ini", "[DatabaseService]\n " . $db('alpha_db'));
    file_put_contents("$dir/sims/beta.ini", "[DatabaseService]\n " . $db('beta_db'));

    return GridConfig::fromFolder($dir);
}

/** The databases by their name; the people are where the accounts of Robust are. */
function estate_connections(array $databases): void
{
    Connections::using(static function ($what, $simulator, $connection) use ($databases): ?OSPDO {
        return $what === 'UserAccountService'
            ? $databases['accounts'] ?? null
            : $databases[$connection?->database ?? ''] ?? null;
    });
}

afterEach(fn() => Connections::using(null));

describe('estates of the simulators', function () {
    function estate_world(): GridConfig
    {
        $alpha = estate_database([101 => 'Alpha Land', 102 => 'Zoo']);
        $alpha->exec(
            "INSERT INTO estate_map VALUES ('aaaaaaaa-0000-0000-0000-000000000001', 101), ('aaaaaaaa-0000-0000-0000-000000000002', 101)",
        );
        $alpha->exec("INSERT INTO estate_users VALUES (101, '22222222-2222-2222-2222-222222222222')");
        $alpha->exec("INSERT INTO estate_groups VALUES (101, '33333333-3333-3333-3333-333333333333')");
        $alpha->exec(
            "INSERT INTO estateban VALUES (101, '" .
                ESTATE_BANNED .
                "', '', '', NULL), (101, '00000000-0000-0000-0000-000000000000', '10.0.0.9', '', NULL)",
        );
        $beta = estate_database([101 => 'Beta Land']);
        $beta->exec("INSERT INTO estate_map VALUES ('bbbbbbbb-0000-0000-0000-000000000001', 101)");
        estate_connections(['accounts' => estate_accounts(), 'alpha_db' => $alpha, 'beta_db' => $beta]);

        return estate_config();
    }

    test('are listed from the database of each simulator, known by the simulator and their number', function () {
        $estates = Estate::all(estate_world());

        expect($estates->total())
            ->toBe(3)
            ->and(array_map(static fn(Estate $e): string => $e->id(), $estates->items()))
            ->toBe(['alpha:101', 'beta:101', 'alpha:102'])
            ->and(array_map(static fn(Estate $e): string => $e->name(), $estates->items()))
            ->toBe(['Alpha Land', 'Beta Land', 'Zoo'])
            ->and($estates->items()[0]->simulator())
            ->toBe('alpha')
            ->and($estates->items()[0]->number())
            ->toBe(101)
            ->and($estates->items()[0]->owner())
            ->toBe(ESTATE_OWNER)
            ->and($estates->items()[0]->ownerName())
            ->toBe('Ada Love');
    });

    test('have their regions, their managers, those who may enter and their bans', function () {
        $estate = Estate::find(estate_world(), 'alpha:101');

        expect($estate->regions())
            ->toBe(['aaaaaaaa-0000-0000-0000-000000000001', 'aaaaaaaa-0000-0000-0000-000000000002'])
            ->and($estate->hasRegion('AAAAAAAA-0000-0000-0000-000000000002'))
            ->toBeTrue()
            ->and($estate->hasRegion('bbbbbbbb-0000-0000-0000-000000000001'))
            ->toBeFalse()
            ->and($estate->managers())
            ->toBe([ESTATE_OWNER])
            ->and($estate->users())
            ->toBe(['22222222-2222-2222-2222-222222222222'])
            ->and($estate->groups())
            ->toBe(['33333333-3333-3333-3333-333333333333'])
            ->and($estate->bans())
            ->toBe([
                ['uuid' => ESTATE_BANNED, 'ip' => '', 'hostMask' => '', 'nameMask' => ''],
                ['uuid' => '', 'ip' => '10.0.0.9', 'hostMask' => '', 'nameMask' => ''],
            ])
            ->and($estate->isBanned(strtoupper(ESTATE_BANNED)))
            ->toBeTrue()
            ->and($estate->isBanned(ESTATE_OWNER))
            ->toBeFalse()
            ->and($estate->isBanned('00000000-0000-0000-0000-000000000000'))
            ->toBeFalse();
    });

    test('are searched, sorted, filtered and paged', function () {
        $config = estate_world();

        expect(
            array_map(static fn(Estate $e): string => $e->name(), Estate::all($config, ['search' => 'land'])->items()),
        )
            ->toBe(['Alpha Land', 'Beta Land'])
            ->and(
                array_map(
                    static fn(Estate $e): string => $e->name(),
                    Estate::all($config, ['sort' => 'name desc'])->items(),
                ),
            )
            ->toBe(['Zoo', 'Beta Land', 'Alpha Land'])
            ->and(
                array_map(
                    static fn(Estate $e): string => $e->id(),
                    Estate::all($config, ['filters' => ['simulator' => 'beta']])->items(),
                ),
            )
            ->toBe(['beta:101'])
            ->and(
                array_map(
                    static fn(Estate $e): string => $e->id(),
                    Estate::all($config, ['filters' => ['region' => 'bbbbbbbb-0000-0000-0000-000000000001']])->items(),
                ),
            )
            ->toBe(['beta:101'])
            ->and(
                array_map(
                    static fn(Estate $e): string => $e->id(),
                    Estate::all($config, ['sort' => 'regions desc'])->items(),
                ),
            )
            ->toBe(['alpha:101', 'beta:101', 'alpha:102'])
            ->and(count(Estate::all($config, ['perPage' => 2, 'page' => 2])->items()))
            ->toBe(1)
            ->and(Estate::all($config, ['perPage' => 2])->total())
            ->toBe(3);
    });

    test(
        'are found by their simulator and number, or by the number alone in the first database that has it',
        function () {
            $config = estate_world();

            expect(Estate::find($config, 'beta:101')->name())
                ->toBe('Beta Land')
                ->and(Estate::find($config, '101')->name())
                ->toBe('Alpha Land')
                ->and(Estate::find($config, '102')->id())
                ->toBe('alpha:102')
                ->and(Estate::find($config, 'beta:102'))
                ->toBeNull()
                ->and(Estate::find($config, 'nobody'))
                ->toBeNull();
        },
    );

    test('are all that is known of them, as data', function () {
        $detail = Estate::find(estate_world(), 'alpha:101')->detail();

        expect($detail)
            ->toMatchArray([
                'id' => 'alpha:101',
                'name' => 'Alpha Land',
                'simulator' => 'alpha',
                'ownerName' => 'Ada Love',
                'public' => true,
            ])
            ->and(count($detail['bans']))
            ->toBe(2)
            ->and(json_encode($detail))
            ->toBeString();
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('estates of the grid', function () {
    test('are read in the database of Robust, known by their number', function () {
        $robust = estate_database([101 => 'My Estate']);
        estate_connections(['accounts' => estate_accounts(), 'robust' => $robust]);

        $estates = Estate::all(estate_config(true));

        expect(array_map(static fn(Estate $e): string => $e->id(), $estates->items()))
            ->toBe(['101'])
            ->and($estates->items()[0]->simulator())
            ->toBeNull()
            ->and(Estate::find(estate_config(true), '101')?->name())
            ->toBe('My Estate');
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('ban list', function () {
    test('is changed in the database of the estate, or told as a statement to review', function () {
        $alpha = estate_database([101 => 'Alpha Land']);
        estate_connections(['accounts' => estate_accounts(), 'alpha_db' => $alpha]);
        $config = estate_config();
        $estate = Estate::find($config, 'alpha:101');

        expect($estate->banSql(ESTATE_BANNED))
            ->toBe(
                "INSERT INTO `alpha_db`.estateban (EstateID, bannedUUID, bannedIp, bannedIpHostMask) VALUES (101, '" .
                    ESTATE_BANNED .
                    "', '', '');",
            )
            ->and($estate->channels())
            ->toBe(['database']);

        $estate->ban(ESTATE_BANNED);
        $banned = Estate::find($config, 'alpha:101');
        expect($banned->isBanned(ESTATE_BANNED))->toBeTrue()->and($banned->banSql(ESTATE_BANNED))->toBeNull();

        $banned->unban(ESTATE_BANNED);
        expect(Estate::find($config, 'alpha:101')->isBanned(ESTATE_BANNED))->toBeFalse();
    });

    test('takes a UUID only', function () {
        estate_connections(['accounts' => estate_accounts(), 'alpha_db' => estate_database([101 => 'Alpha Land'])]);
        $estate = Estate::find(estate_config(), 'alpha:101');

        expect(fn() => $estate->ban("x'; DROP TABLE estateban; --"))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn() => $estate->banSql('nobody'))
            ->toThrow(InvalidArgumentException::class);
    });

    test('is refused when the database of the estate cannot be reached', function () {
        estate_connections(['accounts' => estate_accounts(), 'alpha_db' => estate_database([101 => 'Alpha Land'])]);
        $estate = Estate::find(estate_config(), 'alpha:101');
        Connections::using(static fn(): ?OSPDO => null);

        expect($estate->channels())
            ->toBe([])
            ->and(fn() => $estate->ban(ESTATE_BANNED))
            ->toThrow(ChannelUnavailable::class)
            ->and(fn() => Estate::all(estate_config()))
            ->toThrow(ChannelUnavailable::class);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('region', function () {
    test('knows its estate', function () {
        $config = estate_world();
        $region = OpenSim\Models\Region::fromSearch($config, [
            'regionname' => 'Far',
            'regionuuid' => 'bbbbbbbb-0000-0000-0000-000000000001',
        ]);

        expect($region->estate()?->id())
            ->toBe('beta:101')
            ->and(
                OpenSim\Models\Region::fromSearch($config, [
                    'regionname' => 'Nowhere',
                    'regionuuid' => 'cccccccc-0000-0000-0000-000000000001',
                ])->estate(),
            )
            ->toBeNull();

        Connections::using(static fn(): ?OSPDO => null);
        expect($region->estate())->toBeNull();
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');
