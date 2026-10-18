<?php

declare(strict_types=1);

/**
 * The avatars of a grid through the model: the list (search, sort, filters, pages), one avatar, what is changed, the same when the
 * accounts, the visits and the profiles are in other databases. The databases are in memory (SQLite) with the tables of OpenSimulator.
 */

use OpenSim\Data\OSPDO;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Collection;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Models\Avatar;
use OpenSim\Exceptions\ChannelUnavailable;

/** A grid folder whose Robust says its web address and its databases. */
function avatar_grid(string $extra = ''): GridConfig
{
    $dir = test_tmp() . '/avatars-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents(
        "$dir/Robust.HG.ini",
        "[Const]\n BaseURL = \"http://play.example.org\"\n PublicPort = \"8002\"\n[Hypergrid]\n GatekeeperURI = \"\${Const|BaseURL}:\${Const|PublicPort}\"\n" .
            "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;\"\n$extra",
    );

    return GridConfig::fromFolder($dir);
}

/** A database with the tables of the accounts, the visits and the profiles, and three avatars. */
function avatar_database(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36) PRIMARY KEY, ScopeID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), Email VARCHAR(64), Created INT, UserLevel INT DEFAULT 0, UserFlags INT DEFAULT 0, UserTitle VARCHAR(64) DEFAULT \'\', active INT DEFAULT 1)');
    $pdo->exec('CREATE TABLE GridUser (UserID VARCHAR(255) PRIMARY KEY, HomeRegionID CHAR(36), LastRegionID CHAR(36), Online CHAR(5) DEFAULT \'false\', Login CHAR(16) DEFAULT \'0\', Logout CHAR(16) DEFAULT \'0\')');
    $pdo->exec('CREATE TABLE userprofile (useruuid CHAR(36) PRIMARY KEY, profileImage CHAR(36), profileAboutText TEXT, profileURL VARCHAR(255), profilePartner CHAR(36), profileWantToText TEXT, profileSkillsText TEXT, profileLanguages TEXT, profileFirstImage CHAR(36), profileFirstText TEXT)');
    $pdo->exec('CREATE TABLE userpicks (pickuuid CHAR(36), creatoruuid CHAR(36), name VARCHAR(255), description TEXT, simname VARCHAR(255), posglobal VARCHAR(255), sortorder INT, enabled VARCHAR(5), gatekeeper VARCHAR(255))');
    $pdo->exec('CREATE TABLE classifieds (classifieduuid CHAR(36), creatoruuid CHAR(36), creationdate INT, expirationdate INT, name VARCHAR(255), description TEXT, simname VARCHAR(255), parcelname VARCHAR(255))');
    $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created, UserLevel, UserFlags, active) VALUES
        ('11111111-1111-1111-1111-111111111111', 'Ada', 'Love', 'ada@example.org', 1700000000, 0, 0, 1),
        ('22222222-2222-2222-2222-222222222222', 'Bob', 'Root', 'bob@example.org', 1600000000, 250, 3, 1),
        ('33333333-3333-3333-3333-333333333333', 'Cy', 'Gone', 'cy@example.org', 1650000000, 0, 0, 0)");
    $pdo->exec("INSERT INTO GridUser (UserID, HomeRegionID, LastRegionID, Online, Login, Logout) VALUES
        ('11111111-1111-1111-1111-111111111111', 'aaaa', 'bbbb', 'true', '1700005000', '1700001000'),
        ('22222222-2222-2222-2222-222222222222', 'aaaa', 'aaaa', 'false', '1690000000', '1695000000')");
    $pdo->exec("INSERT INTO userprofile VALUES ('11111111-1111-1111-1111-111111111111', 'img-ada', 'Hello', 'https://ada.example.org', '22222222-2222-2222-2222-222222222222', 'to build', 'scripting', 'en, fr', '00000000-0000-0000-0000-000000000000', 'a mathematician')");
    $pdo->exec("INSERT INTO userpicks VALUES ('k1', '11111111-1111-1111-1111-111111111111', 'The Garden', 'roses', 'Welcome', '<256010,256020,30>', 2, 'true', ''), ('k2', '11111111-1111-1111-1111-111111111111', 'Hidden', 'x', 'Welcome', '<1,1,1>', 1, 'false', ''), ('k3', '11111111-1111-1111-1111-111111111111', 'Alpha pick', 'y', 'Harbour', '<256500,256020,30>', 1, 'true', 'other.example.org:8002')");
    $pdo->exec("INSERT INTO classifieds VALUES ('c1', '11111111-1111-1111-1111-111111111111', 1700000000, 1900000000, 'Roses for sale', 'fresh', 'Welcome', 'The Garden'), ('c2', '11111111-1111-1111-1111-111111111111', 1600000000, 1600000100, 'Old', 'gone', 'Welcome', 'The Garden')");

    return $pdo;
}

/** Everything in one database. */
function avatars_in(OSPDO $pdo): void
{
    Connections::using(static fn() => $pdo);
}

afterEach(fn() => Connections::using(null));

describe('avatars', function () {
    test('are listed with their account, their last visit and their profile', function () {
        avatars_in(avatar_database());
        $list = Avatar::all(avatar_grid());

        expect($list)->toBeInstanceOf(Collection::class)
            ->and($list->count())->toBe(3)
            ->and($list->total())->toBe(3)
            ->and(array_column($list->array(), 'name'))->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and($list->sort())->toBe('name asc');

        $ada = $list->first()->detail();
        expect($ada['uuid'])->toBe('11111111-1111-1111-1111-111111111111')
            ->and($ada['slug'])->toBe('ada-love')
            ->and($ada['uri'])->toBe('ada.love@play.example.org:8002')
            ->and($ada['email'])->toBe('ada@example.org')
            ->and($ada['created'])->toBe(1700000000)
            ->and($ada['online'])->toBeTrue()
            ->and($ada['lastSeen'])->toBe(1700005000)
            ->and($ada['homeRegion'])->toBe('aaaa')
            ->and($ada['profileImage'])->toBe('img-ada')
            ->and($ada['about'])->toBe('Hello')
            ->and($ada['partner'])->toBe('22222222-2222-2222-2222-222222222222')
            ->and($ada['wants'])->toBe('to build')
            ->and($ada['languages'])->toBe('en, fr')
            ->and($ada['firstImage'])->toBeNull()
            ->and($ada['firstLife'])->toBe('a mathematician')
            ->and($ada['technical'])->toBeFalse()
            ->and($list->last()->name())->toBe('Cy Gone');
    });

    test('know their level, their flags and whether they are active, a god and in the world', function () {
        avatars_in(avatar_database());
        [$ada, $bob, $cy] = Avatar::all(avatar_grid())->items();

        expect($bob->isGod())->toBeTrue()->and($bob->level())->toBe(250)->and($bob->flags())->toBe(3)
            ->and($ada->isGod())->toBeFalse()->and($ada->isOnline())->toBeTrue()->and($bob->isOnline())->toBeFalse()
            ->and($cy->isEnabled())->toBeFalse()->and($ada->isEnabled())->toBeTrue()
            ->and($bob->lastSeen()->getTimestamp())->toBe(1695000000)
            ->and($cy->lastSeen())->toBeNull();
    });

    test('are searched by words, each in the first name, the last name or the email', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();

        expect(array_column(Avatar::all($config, ['search' => 'ada'])->array(), 'name'))->toBe(['Ada Love'])
            ->and(array_column(Avatar::all($config, ['search' => 'ove ada'])->array(), 'name'))->toBe(['Ada Love'])
            ->and(array_column(Avatar::all($config, ['search' => 'example.org', 'searchEmail' => true])->array(), 'name'))->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and(Avatar::all($config, ['search' => 'example.org'])->total())->toBe(0)
            ->and(Avatar::all($config, ['search' => 'nobody'])->total())->toBe(0)
            ->and(Avatar::all($config, ['search' => 'ada'])->search())->toBe('ada');
    });

    test('are sorted on a column, in either direction', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $names = static fn(string $sort) => array_column(Avatar::all($config, ['sort' => $sort])->array(), 'name');

        expect($names('name desc'))->toBe(['Cy Gone', 'Bob Root', 'Ada Love'])
            ->and($names('created'))->toBe(['Bob Root', 'Cy Gone', 'Ada Love'])
            ->and($names('lastSeen desc'))->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and($names('level desc'))->toBe(['Bob Root', 'Ada Love', 'Cy Gone'])
            ->and($names('online desc'))->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and($names('nonsense; DROP TABLE x'))->toBe(['Ada Love', 'Bob Root', 'Cy Gone']);
    });

    test('are filtered by active, online and god', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $names = static fn(array $filters) => array_column(Avatar::all($config, ['filters' => $filters])->array(), 'name');

        expect($names(['active' => 'yes']))->toBe(['Ada Love', 'Bob Root'])
            ->and($names(['active' => 'no']))->toBe(['Cy Gone'])
            ->and($names(['online' => 'yes']))->toBe(['Ada Love'])
            ->and($names(['online' => 'no']))->toBe(['Bob Root', 'Cy Gone'])
            ->and($names(['god' => 'yes']))->toBe(['Bob Root'])
            ->and($names(['god' => 'yes', 'active' => 'no']))->toBe([])
            ->and($names(['unknown' => 'yes', 'god' => 'maybe']))->toBe(['Ada Love', 'Bob Root', 'Cy Gone'])
            ->and(Avatar::all($config, ['filters' => ['online' => 'yes']])->applied())->toBe(['online' => 'yes']);
    });

    test('come in pages, the total being the one before the pages', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $first = Avatar::all($config, ['perPage' => 2]);
        $second = Avatar::all($config, ['perPage' => 2, 'page' => 2]);

        expect($first->count())->toBe(2)->and($first->total())->toBe(3)->and($first->pages())->toBe(2)->and($first->page())->toBe(1)
            ->and($second->count())->toBe(1)->and($second->first()->name())->toBe('Cy Gone')->and($second->page())->toBe(2);
    });

    test('say which columns they have and which of them can be sorted', function () {
        avatars_in(avatar_database());
        $list = Avatar::all(avatar_grid());
        $sortable = array_column(array_filter($list->columns(), static fn(array $c): bool => $c['sortable'] ?? false), 'name');

        expect(array_column($list->columns(), 'name'))->toBe(['name', 'email', 'created', 'level', 'active', 'online', 'lastSeen'])
            ->and($sortable)->toBe(['name', 'email', 'created', 'level', 'active', 'online', 'lastSeen'])
            ->and(array_keys($list->filters()))->toBe(['active', 'god', 'technical', 'online']);
    });

    test('are found by their UUID or their name', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();

        expect(Avatar::find($config, '22222222-2222-2222-2222-222222222222')->name())->toBe('Bob Root')
            ->and(Avatar::find($config, 'Ada Love')->uuid())->toBe('11111111-1111-1111-1111-111111111111')
            ->and(Avatar::find($config, 'ada.love'))->toBeNull()
            ->and(Avatar::find($config, 'Ada.Love')->name())->toBe('Ada Love')
            ->and(Avatar::find($config, '99999999-9999-9999-9999-999999999999'))->toBeNull()
            ->and(Avatar::find($config, 'not a name at all'))->toBeNull();
    });

    test('give a list, a JSON and a CSV of themselves', function () {
        avatars_in(avatar_database());
        $list = Avatar::all(avatar_grid(), ['perPage' => 1]);
        $csv = explode("\n", trim($list->csv()));

        expect(json_decode($list->json(), true)[0]['name'])->toBe('Ada Love')
            ->and($csv[0])->toBe('name,email,created,level,active,online,lastSeen')
            ->and($csv[1])->toBe('"Ada Love",ada@example.org,1700000000,0,1,1,1700005000');
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('A search', function () {
    test('looks for the characters it is given: the wildcards of the database are not wildcards', function () {
        $pdo = avatar_database();
        $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Created) VALUES ('77777777-7777-7777-7777-777777777777', 'Under_score', '100%', 1)");
        avatars_in($pdo);
        $config = avatar_grid();
        $names = static fn(string $search) => array_column(Avatar::all($config, ['search' => $search])->array(), 'name');

        expect($names('%'))->toBe(['Under_score 100%'])
            ->and($names('_'))->toBe(['Under_score 100%'])
            ->and($names('r_s'))->toBe(['Under_score 100%'])
            ->and($names('A_a'))->toBe([])
            ->and($names('00%'))->toBe(['Under_score 100%'])
            ->and($names('!'))->toBe([])
            ->and($names('\\'))->toBe([]);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('What a list of avatars offers', function () {
    test('is the visits too when the accounts and the visits are in one database', function () {
        expect(Avatar::capabilities(avatar_grid()))->toBe([
            'sortable' => ['name', 'email', 'created', 'level', 'active', 'online', 'lastSeen'],
            'filters' => ['active', 'god', 'technical', 'online'],
        ])->and(Avatar::capabilities(avatar_grid("[GridUserService]\n ConnectionString = \"Data Source=localhost;Database=visits;User ID=os;Password=pw;\"\n")))->toBe([
            'sortable' => ['name', 'email', 'created', 'level', 'active'],
            'filters' => ['active', 'god', 'technical'],
        ]);
    });
});

describe('account of the services of the grid', function () {
    test('is not a person: the filter leaves it out', function () {
        $pdo = avatar_database();
        $pdo->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Created) VALUES ('99999999-9999-9999-9999-999999999999', 'GRID', 'SERVICES', 1500000000)");
        avatars_in($pdo);
        $config = avatar_grid();
        $names = static fn(array $filters) => array_column(Avatar::all($config, ['filters' => $filters, 'sort' => 'created'])->array(), 'name');

        expect($names([]))->toBe(['GRID SERVICES', 'Bob Root', 'Cy Gone', 'Ada Love'])
            ->and($names(['technical' => 'no']))->toBe(['Bob Root', 'Cy Gone', 'Ada Love'])
            ->and($names(['technical' => 'yes']))->toBe(['GRID SERVICES'])
            ->and(Avatar::find($config, 'GRID SERVICES')->isTechnical())->toBeTrue()
            ->and(Avatar::find($config, 'Ada Love')->isTechnical())->toBeFalse();
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('picks and the classifieds of an avatar', function () {
    test('are what it published and what has not expired', function () {
        avatars_in(avatar_database());
        $ada = Avatar::find(avatar_grid(), 'Ada Love');

        expect(array_column($ada->picks(), 'name'))->toBe(['Alpha pick', 'The Garden'])
            ->and($ada->picks()[0]['gatekeeper'])->toBe('other.example.org:8002')
            ->and(array_column($ada->classifieds(1_750_000_000), 'name'))->toBe(['Roses for sale'])
            ->and($ada->classifieds(1_500_000_000))->toHaveCount(2)
            ->and(Avatar::find(avatar_grid(), 'Bob Root')->picks())->toBe([]);
    });

    test('are none when the profiles cannot be reached', function () {
        avatars_in(avatar_database());
        $ada = Avatar::find(avatar_grid(), 'Ada Love');
        Connections::using(static fn() => null);

        expect($ada->picks())->toBe([])->and($ada->classifieds())->toBe([]);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('An avatar that is changed', function () {
    test('is changed in the database of the accounts, for the email, the title, the level and active', function () {
        $pdo = avatar_database();
        avatars_in($pdo);
        $config = avatar_grid();
        $cy = Avatar::find($config, 'Cy Gone');

        expect($cy->channels())->toBe(['database']);
        $cy->enable();
        $cy->update(['email' => 'cy@new.example.org', 'title' => 'Ghost', 'level' => 10]);
        $cy = Avatar::find($config, 'Cy Gone');
        expect($cy->isEnabled())->toBeTrue()->and($cy->detail()['email'])->toBe('cy@new.example.org')
            ->and($cy->detail()['title'])->toBe('Ghost')->and($cy->level())->toBe(10);

        $cy->disable();
        expect(Avatar::find($config, 'Cy Gone')->isEnabled())->toBeFalse();
    });

    test('keeps its name: only the listed values can be changed', function () {
        avatars_in(avatar_database());
        $ada = Avatar::find(avatar_grid(), 'Ada Love');

        expect(fn() => $ada->update(['firstName' => 'Eve']))->toThrow(InvalidArgumentException::class);
    });

    test('is not created and not deleted: no channel does it', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();

        expect(fn() => Avatar::create($config, ['firstName' => 'New']))->toThrow(ChannelUnavailable::class)
            ->and(fn() => Avatar::find($config, 'Ada Love')->delete())->toThrow(ChannelUnavailable::class);
    });

    test('is refused when the database of the accounts cannot be reached', function () {
        avatars_in(avatar_database());
        $config = avatar_grid();
        $ada = Avatar::find($config, 'Ada Love');
        Connections::using(static fn() => null);

        expect($ada->channels())->toBe([])
            ->and(fn() => $ada->disable())->toThrow(ChannelUnavailable::class)
            ->and(fn() => Avatar::all($config))->toThrow(ChannelUnavailable::class);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('avatars of a grid whose services have their own databases', function () {
    test('are read from each, the visits and the profiles of the page added to the accounts', function () {
        $accounts = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $accounts->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36) PRIMARY KEY, ScopeID CHAR(36), FirstName VARCHAR(64), LastName VARCHAR(64), Email VARCHAR(64), Created INT, UserLevel INT DEFAULT 0, UserFlags INT DEFAULT 0, UserTitle VARCHAR(64) DEFAULT \'\', active INT DEFAULT 1)');
        $accounts->exec("INSERT INTO UserAccounts (PrincipalID, FirstName, LastName, Email, Created) VALUES ('11111111-1111-1111-1111-111111111111', 'Ada', 'Love', 'ada@example.org', 1700000000), ('22222222-2222-2222-2222-222222222222', 'Bob', 'Root', 'bob@example.org', 1600000000)");
        $visits = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $visits->exec('CREATE TABLE GridUser (UserID VARCHAR(255) PRIMARY KEY, HomeRegionID CHAR(36), LastRegionID CHAR(36), Online CHAR(5), Login CHAR(16), Logout CHAR(16))');
        $visits->exec("INSERT INTO GridUser VALUES ('11111111-1111-1111-1111-111111111111', 'home', 'last', 'true', '100', '200')");
        $profiles = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
        $profiles->exec('CREATE TABLE userprofile (useruuid CHAR(36), profileImage CHAR(36), profileAboutText TEXT, profileURL VARCHAR(255), profilePartner CHAR(36), profileWantToText TEXT, profileSkillsText TEXT, profileLanguages TEXT, profileFirstImage CHAR(36), profileFirstText TEXT)');
        $profiles->exec("INSERT INTO userprofile (useruuid, profileImage, profileAboutText) VALUES ('22222222-2222-2222-2222-222222222222', 'img-bob', 'Root')");
        Connections::using(static fn(Database|string $what) => match ($what) {
            'UserAccountService' => $accounts,
            'GridUserService' => $visits,
            Database::Profiles => $profiles,
            default => null,
        });
        $config = avatar_grid(
            "[GridUserService]\n ConnectionString = \"Data Source=localhost;Database=visits;User ID=os;Password=pw;\"\n" .
                "[UserProfilesService]\n ConnectionString = \"Data Source=localhost;Database=profiles;User ID=os;Password=pw;\"\n",
        );

        $list = Avatar::all($config, ['sort' => 'lastSeen desc', 'filters' => ['online' => 'yes']]);
        [$ada, $bob] = $list->items();

        expect($list->sort())->toBe('name asc')
            ->and($list->total())->toBe(2)
            ->and($list->applied())->toBe([])
            ->and(array_keys($list->filters()))->toBe(['active', 'god', 'technical'])
            ->and($ada->isOnline())->toBeTrue()
            ->and($ada->lastSeen()->getTimestamp())->toBe(200)
            ->and($ada->detail()['homeRegion'])->toBe('home')
            ->and($bob->isOnline())->toBeFalse()
            ->and($bob->detail()['profileImage'])->toBe('img-bob')
            ->and(array_column(array_filter($list->columns(), static fn(array $c) => $c['sortable']), 'name'))->toBe(['name', 'email', 'created', 'level', 'active']);
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('A list of a database that cannot be reached', function () {
    test('says why: what the server answered', function () {
        $dir = test_tmp() . '/unreachable-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents("$dir/Robust.HG.ini", "[DatabaseService]\n ConnectionString = \"Data Source=127.0.0.1:1;Database=robust;User ID=os;Password=secret;\"\n");
        $config = GridConfig::fromFolder($dir);

        try {
            Avatar::all($config);
            $message = '';
        } catch (ChannelUnavailable $e) {
            $message = $e->getMessage();
        }

        expect($message)->toContain('cannot read the avatars')->toContain('ERROR 2002')->not->toContain('secret')
            ->and(Connections::reason($config, 'UserAccountService'))->toContain('ERROR 2002');
    });

    test('says the config is incomplete when it does not say where the database is', function () {
        $dir = test_tmp() . '/nodb-' . bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        file_put_contents("$dir/Robust.HG.ini", "[Const]\n BaseURL = \"http://play.example.org\"\n");

        expect(fn() => Avatar::all(GridConfig::fromFolder($dir)))->toThrow(ChannelUnavailable::class, 'does not say where');
    });
})->skip(fn() => !extension_loaded('pdo_mysql'), 'pdo_mysql is missing');
