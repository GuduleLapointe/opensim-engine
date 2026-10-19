<?php

declare(strict_types=1);

/**
 * The search: the simulators that register, the snapshots they give, the events of a calendar, and the questions of the viewers
 * (XML-RPC) that read the result.
 */

use OpenSim\Services\EventsParser;
use OpenSim\Services\Failure;
use OpenSim\Services\SearchParser;
use OpenSim\Services\SearchQuery;
use OpenSim\Services\SearchRegistry;
use OpenSim\Services\XmlRpc;
use OpenSim\Testing\ClockFake;
use PhpXmlRpc\Encoder;
use PhpXmlRpc\Request;

const SEARCH_REGION = '11111111-1111-4111-8111-111111111111';
const SEARCH_PARCEL = '22222222-2222-4222-8222-222222222222';
const SEARCH_SALE = '33333333-3333-4333-8333-333333333333';
const SEARCH_INFO = '44444444-4444-4444-8444-444444444444';
const SEARCH_GK = 'http://grid.example.org:8002';

/** The tables of the search, as much as the code reads and writes, in the database the tests use. */
function search_database(): OpenSim\Data\OSPDO
{
    $pdo = test_pdo();
    foreach (
        [
            'CREATE TABLE hostsregister (host VARCHAR(255), port INT, register INT, nextcheck INT, checked INT, failcounter INT, gatekeeperURL VARCHAR(255), PRIMARY KEY (host, port))',
            'CREATE TABLE regionsregister (regionname VARCHAR(255), regionUUID CHAR(36) PRIMARY KEY, regionhandle VARCHAR(255), url VARCHAR(255), owner VARCHAR(255), owneruuid CHAR(36), gatekeeperURL VARCHAR(255))',
            'CREATE TABLE allparcels (regionUUID CHAR(36), parcelname VARCHAR(255), ownerUUID CHAR(36), groupUUID CHAR(36), landingpoint VARCHAR(255), parcelUUID CHAR(36) PRIMARY KEY, infoUUID CHAR(36), parcelarea INT, gatekeeperURL VARCHAR(255))',
            'CREATE TABLE parcels (parcelUUID CHAR(36), regionUUID CHAR(36), parcelname VARCHAR(255), landingpoint VARCHAR(255), description VARCHAR(255), searchcategory VARCHAR(50), build VARCHAR(5), script VARCHAR(5), public VARCHAR(5), dwell FLOAT, infouuid VARCHAR(36), mature VARCHAR(10), gatekeeperURL VARCHAR(255), imageUUID CHAR(36), PRIMARY KEY (regionUUID, parcelUUID))',
            'CREATE TABLE parcelsales (regionUUID CHAR(36), parcelname VARCHAR(255), parcelUUID CHAR(36), area INT, saleprice INT, landingpoint VARCHAR(255), infoUUID CHAR(36), dwell INT, parentestate INT, mature VARCHAR(10), gatekeeperURL VARCHAR(255), PRIMARY KEY (regionUUID, parcelUUID))',
            'CREATE TABLE popularplaces (parcelUUID CHAR(36) PRIMARY KEY, name VARCHAR(255), dwell FLOAT, infoUUID CHAR(36), has_picture INT, mature VARCHAR(10), gatekeeperURL VARCHAR(255))',
            'CREATE TABLE objects (objectuuid CHAR(36), parceluuid CHAR(36), location VARCHAR(255), name VARCHAR(255), description VARCHAR(255), regionuuid CHAR(36), gatekeeperURL VARCHAR(255), PRIMARY KEY (objectuuid, parceluuid))',
            'CREATE TABLE oshelpers_cache (cache_key VARCHAR(255) PRIMARY KEY, cache_value BLOB, cache_expires INT)',
            'CREATE TABLE classifieds (classifieduuid CHAR(36) PRIMARY KEY)',
            'CREATE TABLE events (owneruuid CHAR(36), name VARCHAR(255), eventid INTEGER PRIMARY KEY, creatoruuid CHAR(36), category INT, description TEXT, dateUTC INT, duration INT, covercharge INT, coveramount INT, simname VARCHAR(255), parcelUUID CHAR(36), globalPos VARCHAR(255), eventflags INT, gatekeeperURL VARCHAR(255))',
        ]
        as $sql
    ) {
        $pdo->exec($sql);
    }

    return $pdo;
}

/** The snapshot a simulator gives: one region with a parcel shown in search, a parcel for sale, a parcel hidden, and an object. */
function search_snapshot(string $expire = '3600'): string
{
    $parcel = static fn(
        string $uuid,
        string $name,
        array $attributes,
        string $extra = '',
    ): string => "<parcel category=\"{$attributes['category']}\" forsale=\"{$attributes['forsale']}\" showinsearch=\"{$attributes['showinsearch']}\" build=\"true\" scripts=\"false\" public=\"true\" salesprice=\"{$attributes['price']}\">" .
        "<name>$name</name><uuid>$uuid</uuid><infouuid>" .
        SEARCH_INFO .
        "</infouuid><location>128/128/25</location><description>About $name</description><area>1024</area><dwell>12.5</dwell>$extra" .
        '<owner><uuid>55555555-5555-4555-8555-555555555555</uuid></owner></parcel>';

    return '<?xml version="1.0"?><snapshot><gatekeeperURL>' .
        SEARCH_GK .
        '</gatekeeperURL><regiondata><expire>' .
        $expire .
        '</expire>' .
        '<region category="Mature"><info><uuid>' .
        SEARCH_REGION .
        '</uuid><name>Welcome</name><handle>1099511628032000</handle><url>http://sim.example.org:9000/</url></info>' .
        '<data><estate><id>101</id><name>Ada Love</name><uuid>66666666-6666-4666-8666-666666666666</uuid></estate>' .
        $parcel(
            SEARCH_PARCEL,
            'Rose garden',
            ['category' => 'Residential', 'forsale' => 'false', 'showinsearch' => 'true', 'price' => '0'],
            '<image>77777777-7777-4777-8777-777777777777</image><group><groupuuid>88888888-8888-4888-8888-888888888888</groupuuid></group>',
        ) .
        $parcel(SEARCH_SALE, 'Empty lot', [
            'category' => 'Other',
            'forsale' => 'true',
            'showinsearch' => 'true',
            'price' => '250',
        ]) .
        $parcel('99999999-9999-4999-8999-999999999999', 'Hidden', [
            'category' => 'Other',
            'forsale' => 'false',
            'showinsearch' => 'false',
            'price' => '0',
        ]) .
        '<object><uuid>aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa</uuid><title>Fountain</title><location>10/20/30</location><description>Water</description><parceluuid>' .
        SEARCH_PARCEL .
        '</parceluuid><regionuuid>' .
        SEARCH_REGION .
        '</regionuuid></object>' .
        '</data></region></regiondata></snapshot>';
}

/** A search over the fixture database, with the snapshot of one simulator read into it. */
function searched(): array
{
    $db = search_database();
    $clock = new ClockFake();
    $registry = new SearchRegistry($db, 'regionsregister', $clock);
    $registry->register('sim.example.org', 9000);
    (new SearchParser($registry, $db, 'regionsregister'))->scan('sim.example.org', 9000, search_snapshot());

    return [$db, $registry, $clock];
}

/** What an XML-RPC method gives to a request, decoded. */
function rpc_call(XmlRpc $rpc, string $method, array $request): array
{
    $encoder = new Encoder();
    $answer = $rpc->call((new Request($method, [$encoder->encode($request)]))->serialize());

    return [
        'status' => $answer->status,
        'body' => $answer->body(),
        'data' =>
            XmlRpc::parse(
                str_replace(
                    ['<params>', '</params>'],
                    ['<methodCall><methodName>x</methodName><params>', '</params></methodCall>'],
                    preg_replace('/^<\?xml[^>]*>/', '', $answer->body()) ?? '',
                ),
            )['params'][0] ?? null,
    ];
}

describe('XML-RPC', function () {
    test(
        'gives the struct of a request to a method and writes what it answers like the extension of PHP did',
        function () {
            $rpc = new XmlRpc(['echo' => static fn(array $request): array => XmlRpc::envelope(true, '', [$request])]);

            $answer = rpc_call($rpc, 'echo', ['text' => 'hello']);

            expect($answer['status'])
                ->toBe(200)
                ->and($answer['body'])
                ->toStartWith("<?xml version=\"1.0\" encoding=\"utf-8\" ?>\n<params>\n<param>\n")
                ->and($answer['data'])
                ->toBe(['success' => true, 'errorMessage' => '', 'data' => [['text' => 'hello']]]);
        },
    );

    test('tells what it cannot answer: a status with the failure, a fault for a method nobody knows', function () {
        $rpc = new XmlRpc(['no' => static fn(array $request): array => throw new Failure('Forbidden', 403)]);

        $failed = rpc_call($rpc, 'no', []);
        $unknown = rpc_call($rpc, 'nothing', []);
        $junk = $rpc->call('not xml');

        expect($failed['status'])
            ->toBe(403)
            ->and($failed['data'])
            ->toBe(['success' => false, 'errorMessage' => 'Forbidden'])
            ->and($unknown['status'])
            ->toBe(200)
            ->and($unknown['body'])
            ->toContain('<fault>')
            ->and($junk->status)
            ->toBe(400)
            ->and(XmlRpc::parse(''))
            ->toBeNull()
            ->and(XmlRpc::plain(true))
            ->toContain('<boolean>true</boolean>')
            ->and(XmlRpc::plain(false, 'a < b'))
            ->toContain('<error>a &lt; b</error>');
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The registry of the search', function () {
    test('registers a simulator once, and registers it again at its next start', function () {
        $db = search_database();
        $clock = new ClockFake();
        $registry = new SearchRegistry($db, 'regionsregister', $clock);

        $registry->register('sim.example.org', 9000);
        $registry->schedule('sim.example.org', 9000, 5000, 3);
        $clock->now += 100;
        $registry->register('sim.example.org', 9000);

        expect($db->get_results('SELECT * FROM hostsregister', [], PDO::FETCH_ASSOC))->toBe([
            [
                'host' => 'sim.example.org',
                'port' => 9000,
                'register' => 1100,
                'nextcheck' => 0,
                'checked' => 0,
                'failcounter' => 0,
                'gatekeeperURL' => '',
            ],
        ]);
    });

    test('lists the hosts that are due, and when every host was read, lists them all again', function () {
        $db = search_database();
        $clock = new ClockFake();
        $registry = new SearchRegistry($db, 'regionsregister', $clock);
        $registry->register('a.example.org', 9000);
        $registry->register('b.example.org', 9000);

        expect($registry->due())->toHaveCount(2);
        $registry->schedule('a.example.org', 9000, (int) $clock->now + 600);
        $registry->schedule('b.example.org', 9000, (int) $clock->now - 1);
        expect($registry->due())
            ->toBe([['host' => 'b.example.org', 'port' => 9000]])
            ->and($registry->waiting())
            ->toBeTrue();
        $registry->schedule('b.example.org', 9000, (int) $clock->now + 600);
        // Everyone is read: the next pass starts again with the hosts that are due, whatever they were marked
        expect($registry->due())->toBe([])->and($registry->waiting())->toBeFalse();
        $clock->now += 700;
        expect($registry->due())->toHaveCount(2);
    });

    test('doubles the time between two looks at a host each time it does not answer', function () {
        expect([SearchRegistry::backoff(0), SearchRegistry::backoff(1), SearchRegistry::backoff(3)])->toBe([
            600,
            1200,
            4800,
        ]);
    });

    test('reads a simulator again soon after it registers, unless it failed', function () {
        $db = search_database();
        $clock = new ClockFake();
        $registry = new SearchRegistry($db, 'regionsregister', $clock);
        $registry->register('a.example.org', 9000);
        $registry->register('b.example.org', 9000);
        $registry->schedule('b.example.org', 9000, 7777, 2);

        $registry->again('a.example.org', 9000);
        $registry->again('b.example.org', 9000);

        expect($db->get_var('SELECT nextcheck FROM hostsregister WHERE host = ?', ['a.example.org']))
            ->toBe(1090)
            ->and($db->get_var('SELECT nextcheck FROM hostsregister WHERE host = ?', ['b.example.org']))
            ->toBe(7777);
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The parser of the search', function () {
    test(
        'reads the snapshot of a simulator into the tables: regions, parcels shown, land for sale, popular places, objects',
        function () {
            [$db, $registry] = searched();

            $region = $db->get_row('SELECT * FROM regionsregister', [], PDO::FETCH_ASSOC);
            $parcel = $db->get_row('SELECT * FROM parcels', [], PDO::FETCH_ASSOC);

            expect($region)
                ->toMatchArray([
                    'regionname' => 'Welcome',
                    'regionUUID' => SEARCH_REGION,
                    'owner' => 'Ada Love',
                    'owneruuid' => '66666666-6666-4666-8666-666666666666',
                    'gatekeeperURL' => SEARCH_GK,
                ])
                ->and($db->get_var('SELECT COUNT(*) FROM allparcels'))
                ->toBe(3)
                ->and($db->get_var('SELECT COUNT(*) FROM parcels'))
                ->toBe(2)
                ->and($parcel)
                ->toMatchArray([
                    'parcelname' => 'Rose garden',
                    'mature' => 'Mature',
                    'searchcategory' => 'Residential',
                    'imageUUID' => '77777777-7777-4777-8777-777777777777',
                    'gatekeeperURL' => SEARCH_GK,
                ])
                ->and($db->get_results('SELECT name, has_picture FROM popularplaces', [], PDO::FETCH_ASSOC))
                ->toBe([['name' => 'Rose garden', 'has_picture' => 1]])
                ->and($db->get_row('SELECT * FROM parcelsales', [], PDO::FETCH_ASSOC))
                ->toMatchArray(['parcelname' => 'Empty lot', 'saleprice' => 250, 'area' => 1024, 'parentestate' => 101])
                ->and($db->get_var('SELECT name FROM objects'))
                ->toBe('10/20/30')
                ->and($db->get_row('SELECT nextcheck, gatekeeperURL FROM hostsregister', [], PDO::FETCH_ASSOC))
                ->toBe(['nextcheck' => 1000 + 3600, 'gatekeeperURL' => SEARCH_GK]);
        },
    );

    test('replaces what it knew of a region by what the simulator says now', function () {
        [$db, $registry] = searched();
        $again = str_replace('Rose garden', 'Rose garden renamed', search_snapshot());

        (new SearchParser($registry, $db, 'regionsregister'))->scan(
            'sim.example.org',
            9000,
            str_replace(
                [SEARCH_SALE, 'forsale="true"'],
                ['33333333-3333-4333-8333-333333333334', 'forsale="false"'],
                $again,
            ),
        );

        expect($db->get_var("SELECT parcelname FROM parcels WHERE parcelUUID = '" . SEARCH_PARCEL . "'"))
            ->toBe('Rose garden renamed')
            ->and($db->get_var('SELECT COUNT(*) FROM regionsregister'))
            ->toBe(1)
            ->and($db->get_var('SELECT COUNT(*) FROM parcelsales'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM popularplaces'))
            ->toBe(2);
    });

    test('ignores what is not a snapshot', function () {
        $db = search_database();
        $registry = new SearchRegistry($db, 'regionsregister', new ClockFake());
        $parser = new SearchParser($registry, $db, 'regionsregister');

        $parser->scan('sim.example.org', 9000, '<html>404</html>');
        $parser->scan('sim.example.org', 9000, 'not xml at all');
        $parser->scan('sim.example.org', 9000, '<snapshot><regiondata></regiondata></snapshot>');

        expect($db->get_var('SELECT COUNT(*) FROM regionsregister'))->toBe(0);
    });

    test(
        'reads the hosts that are due, schedules the next look from what the snapshot says, and waits longer for a host that fails',
        function () {
            $db = search_database();
            $clock = new ClockFake();
            $registry = new SearchRegistry($db, 'regionsregister', $clock);
            $registry->register('sim.example.org', 9000);
            $registry->register('down.example.org', 9000);
            $asked = [];
            $http = static function (string $url) use (&$asked): ?string {
                $asked[] = $url;

                return $url === 'http://sim.example.org:9000/?method=collector' ? search_snapshot('1800') : null;
            };
            $parser = new SearchParser($registry, $db, 'regionsregister', $http);

            $looked = $parser->parse();

            expect($looked)
                ->toBe(2)
                ->and($asked)
                ->toContain('http://down.example.org:9000/?method=collector')
                ->and(
                    $db->get_row(
                        "SELECT nextcheck, failcounter, checked FROM hostsregister WHERE host = 'sim.example.org'",
                        [],
                        PDO::FETCH_ASSOC,
                    ),
                )
                ->toBe(['nextcheck' => 1000 + 1800, 'failcounter' => 0, 'checked' => 1])
                ->and(
                    $db->get_row(
                        "SELECT nextcheck, failcounter FROM hostsregister WHERE host = 'down.example.org'",
                        [],
                        PDO::FETCH_ASSOC,
                    ),
                )
                ->toBe(['nextcheck' => 1000 + 1200, 'failcounter' => 1])
                ->and($db->get_var('SELECT COUNT(*) FROM regionsregister'))
                ->toBe(1);
        },
    );

    test('forgets a host that failed too many times, with what it told', function () {
        [$db, $registry] = searched();
        $registry->schedule('sim.example.org', 9000, 0, SearchParser::MAX_FAILURES);
        $parser = new SearchParser($registry, $db, 'regionsregister', static fn(string $url): ?string => null);

        $parser->check('sim.example.org', 9000);

        expect($db->get_var('SELECT COUNT(*) FROM hostsregister'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM regionsregister'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM parcels'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM popularplaces'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM objects'))
            ->toBe(0);
    });

    test('forgets a simulator that unregisters', function () {
        [$db, $registry] = searched();

        $registry->unregister('sim.example.org', 9000);

        expect($db->get_var('SELECT COUNT(*) FROM allparcels'))
            ->toBe(0)
            ->and($db->get_var('SELECT COUNT(*) FROM parcelsales'))
            ->toBe(0);
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The events of the search', function () {
    /** A calendar with an event to come, one that is over, and one in a place that does not answer. */
    function calendar_events(ClockFake $clock): string
    {
        $at = static fn(int $seconds): string => gmdate('Y-m-d\TH:i:s\Z', (int) $clock->now + $seconds);

        return json_encode([
            [
                'title' => 'Concert &amp; party',
                'description' => '<p>Come <b>in</b></p>',
                'start' => $at(7200),
                'end' => $at(10800),
                'categories' => ['Music'],
                'hgurl' => 'grid.example.org:8002:Welcome/10/20/30',
            ],
            [
                'title' => 'Over',
                'description' => '',
                'start' => $at(-7200),
                'end' => $at(-3600),
                'categories' => [],
                'hgurl' => 'grid.example.org:8002:Welcome',
            ],
            [
                'title' => 'Lost',
                'description' => '',
                'start' => $at(3600),
                'end' => $at(3600),
                'categories' => ['whatever'],
                'hgurl' => 'gone.example.org:8002:Nowhere',
            ],
        ]);
    }

    test('are replaced by the ones of the calendar that are not over and whose region answers', function () {
        $db = search_database();
        $clock = new ClockFake();
        $clock->now = 1_700_000_000.0;
        $db->exec("INSERT INTO events (eventid, name, dateUTC) VALUES (99, 'Old', 1)");
        $places = [
            'online' => static fn(string $url): bool => !str_contains($url, 'gone'),
            'parts' => static fn(string $url): array => [
                'gatekeeper' => 'http://grid.example.org:8002',
                'pos' => str_contains($url, '/10/') ? '10/20/30' : '',
            ],
            'located' => static fn(string $url): array => ['x' => 256000, 'y' => 256000],
            'link' => static fn(string $url, int $format): string => "link:$format:$url",
        ];
        $parser = new EventsParser(
            $db,
            'https://calendar.example.org/events',
            static fn(string $url): ?string => $url === 'https://calendar.example.org/events/events.json'
                ? calendar_events($clock)
                : null,
            $places,
            clock: $clock,
        );

        $kept = $parser->parse();
        $event = $db->get_row('SELECT * FROM events', [], PDO::FETCH_ASSOC);

        expect($kept)
            ->toBe(1)
            ->and($db->get_var('SELECT COUNT(*) FROM events'))
            ->toBe(1)
            ->and($event)
            ->toMatchArray([
                'name' => 'Concert & party',
                'category' => 20,
                'duration' => 60,
                'dateUTC' => 1_700_007_200,
                'globalPos' => '256010,256020,30',
                'gatekeeperURL' => 'http://grid.example.org:8002',
                'simname' => 'link:16:grid.example.org:8002:Welcome/10/20/30',
            ])
            ->and($event['description'])
            ->toStartWith('link:40:grid.example.org:8002:Welcome/10/20/30')
            ->toEndWith('Come in');
    });

    test('are left as they are when the calendar does not answer', function () {
        $db = search_database();
        $db->exec("INSERT INTO events (eventid, name, dateUTC) VALUES (99, 'Kept', 1)");
        $parser = new EventsParser($db, 'https://calendar.example.org/events', static fn(string $url): ?string => null);

        expect($parser->parse())
            ->toBe(-1)
            ->and($db->get_var('SELECT name FROM events'))
            ->toBe('Kept');
    });

    test(
        'have the category of the names a calendar gives: miscellaneous for a name nobody knows, none for no name',
        function () {
            expect([
                EventsParser::category(null),
                EventsParser::category([]),
                EventsParser::category(['Sports']),
                EventsParser::category(['unknown', 'LECTURE']),
                EventsParser::category(['unknown']),
                EventsParser::category([22]),
            ])->toBe([0, 0, 19, 27, 29, 22]);
        },
    );
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

describe('The questions of the search', function () {
    test('find the places by their words, rated and ordered, for the grid that asks', function () {
        [$db] = searched();
        $query = new SearchQuery($db, 'regionsregister');
        $rpc = new XmlRpc($query->methods());

        $found = rpc_call($rpc, 'dir_places_query', [
            'text' => 'rose',
            'flags' => (string) (1 << 25),
            'query_start' => '0',
        ])['data'];
        $none = rpc_call($rpc, 'dir_places_query', ['text' => 'rose', 'flags' => (string) (1 << 24)])['data'];
        $other = rpc_call(
            new XmlRpc((new SearchQuery($db, 'regionsregister', gk: 'other.example.org:8002'))->methods()),
            'dir_places_query',
            ['text' => 'rose'],
        )['data'];
        $invalid = rpc_call($rpc, 'dir_places_query', ['text' => ' '])['data'];

        expect($found['success'])
            ->toBeTrue()
            ->and($found['data'][0])
            ->toMatchArray([
                'parcel_id' => SEARCH_INFO,
                'name' => 'Rose garden',
                'for_sale' => 'False',
                'auction' => 'False',
                'regionname' => 'Welcome',
            ])
            ->and($none)
            ->toBe(['success' => false, 'errorMessage' => 'No results'])
            ->and($other['success'])
            ->toBeFalse()
            ->and($invalid)
            ->toBe(['success' => false, 'errorMessage' => 'Invalid search terms'])
            ->and(
                rpc_call(
                    new XmlRpc((new SearchQuery($db, 'regionsregister', gk: 'grid.example.org:8002'))->methods()),
                    'dir_places_query',
                    ['text' => 'rose'],
                )['data']['success'],
            )
            ->toBeTrue();
    });

    test('list the popular places, without the land for sale and, when asked, without the other grids', function () {
        [$db] = searched();
        $rpc = new XmlRpc((new SearchQuery($db, 'regionsregister'))->methods());

        $all = rpc_call($rpc, 'dir_popular_query', ['flags' => (string) (1 << 12), 'text' => 'rose'])['data'];
        $nothing = rpc_call($rpc, 'dir_popular_query', ['text' => 'lot'])['data'];
        $local = rpc_call($rpc, 'dir_popular_query', [
            'include_hypergrid' => 'false',
            'gatekeeper_url' => 'other.example.org:8002',
        ])['data'];

        expect($all['data'])
            ->toHaveCount(1)
            ->and($all['data'][0])
            ->toMatchArray([
                'parcel_id' => SEARCH_INFO,
                'name' => 'Rose garden',
                'regionname' => 'Welcome',
                'regionUUID' => SEARCH_REGION,
                'imageUUID' => '77777777-7777-4777-8777-777777777777',
            ])
            ->and($nothing['data'] ?? [])
            ->toBe([])
            ->and($local['data'] ?? [])
            ->toBe([]);
    });

    test('list the land for sale, by price per meter, with the limits of the viewer', function () {
        [$db] = searched();
        $rpc = new XmlRpc((new SearchQuery($db, 'regionsregister'))->methods());

        $sales = rpc_call($rpc, 'dir_land_query', [
            'flags' => '0',
            'type' => '4294967295',
            'price' => '0',
            'area' => '0',
        ])['data'];
        $cheap = rpc_call($rpc, 'dir_land_query', [
            'flags' => (string) (1 << 20),
            'type' => '4294967295',
            'price' => '100',
            'area' => '0',
        ])['data'];
        $auctions = rpc_call($rpc, 'dir_land_query', ['flags' => '0', 'type' => '2', 'price' => '0', 'area' => '0'])[
            'data'
        ];

        expect($sales['data'][0])
            ->toBe([
                'parcel_id' => SEARCH_INFO,
                'name' => 'Empty lot',
                'auction' => 'false',
                'for_sale' => 'true',
                'sale_price' => 250,
                'landing_point' => '128/128/25',
                'region_UUID' => SEARCH_REGION,
                'area' => 1024,
            ])
            ->and($cheap['data'] ?? [])
            ->toBe([])
            ->and($auctions)
            ->toBe(['success' => false, 'errorMessage' => 'No auctions listed']);
    });

    test('list the events of a day, or the ones to come, by category and words, and tell one in full', function () {
        $db = search_database();
        $clock = new ClockFake();
        $clock->now = 1_700_000_000.0;
        $db->exec(
            "INSERT INTO events VALUES ('o', 'Jazz night', 1, 'c', 20, 'Live jazz', " .
                (1_700_000_000 + 3600) .
                ", 60, 0, 0, 'Welcome (grid.example.org:8002)', 'p', '128,128,25', 0, '" .
                SEARCH_GK .
                "'), ('o', 'Old', 2, 'c', 19, 'Gone', 1000, 60, 0, 0, 'Somewhere', 'p', '1,1,1', 0, '')",
        );
        $rpc = new XmlRpc((new SearchQuery($db, 'regionsregister', gk: SEARCH_GK, clock: $clock))->methods());

        $coming = rpc_call($rpc, 'dir_events_query', ['text' => 'u|0|jazz', 'flags' => '0'])['data'];
        $category = rpc_call($rpc, 'dir_events_query', ['text' => 'u|19|', 'flags' => '0'])['data'];
        $invalid = rpc_call($rpc, 'dir_events_query', ['text' => '%%%'])['data'];
        $info = rpc_call($rpc, 'event_info_query', ['eventID' => '1'])['data']['data'][0];

        expect($coming['data'])
            ->toHaveCount(1)
            ->and($coming['data'][0])
            ->toMatchArray([
                'owner_id' => 'o',
                'name' => 'Jazz night',
                'event_id' => 1,
                'unix_time' => 1_700_003_600,
                'landing_point' => '128,128,25',
            ])
            ->and($category['data'] ?? [])
            ->toBe([])
            ->and($invalid)
            ->toBe(['success' => false, 'errorMessage' => 'Invalid search terms'])
            ->and($info)
            ->toMatchArray([
                'name' => 'Jazz night',
                'category' => 'Live Music',
                'duration' => 60,
                'simname' => ' (grid.example.org:8002)',
            ]);
    });

    test('list the classifieds of the profiles, and tell one in full, when the grid has them', function () {
        $profiles = test_pdo();
        $profiles->exec(
            'CREATE TABLE classifieds (classifieduuid CHAR(36), creatoruuid CHAR(36), creationdate INT, expirationdate INT, category VARCHAR(20), name VARCHAR(255), description TEXT, parceluuid CHAR(36), parentestate INT, snapshotuuid CHAR(36), simname VARCHAR(255), posglobal VARCHAR(255), parcelname VARCHAR(255), classifiedflags INT, priceforlisting INT)',
        );
        $profiles->exec(
            "INSERT INTO classifieds VALUES ('cccccccc-cccc-4ccc-8ccc-cccccccccccc', 'creator', 1, 2, '3', 'Shop here', 'Best shop', 'p', 1, 's', 'Welcome', '1,1,1', 'Mall', 4, 50)",
        );
        $rpc = new XmlRpc((new SearchQuery(search_database(), 'regionsregister', profiles: $profiles))->methods());

        $list = rpc_call($rpc, 'dir_classified_query', ['text' => 'shop', 'flags' => '4', 'category' => '3'])['data'];
        $mature = rpc_call($rpc, 'dir_classified_query', ['text' => 'shop', 'flags' => '8', 'category' => '0'])['data'];
        $info = rpc_call($rpc, 'classifieds_info_query', ['classifiedID' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'])[
            'data'
        ];
        $without = new XmlRpc((new SearchQuery(search_database(), 'regionsregister'))->methods());

        expect($list['data'][0])
            ->toBe([
                'classifiedid' => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc',
                'name' => 'Shop here',
                'classifiedflags' => 4,
                'creation_date' => 1,
                'expiration_date' => 2,
                'priceforlisting' => 50,
            ])
            ->and($mature['data'] ?? [])
            ->toBe([])
            ->and($info['data'][0])
            ->toMatchArray(['name' => 'Shop here', 'parcelname' => 'Mall', 'priceforlisting' => 50])
            ->and($without->call(rpc_xml('dir_classified_query', ['text' => 'x']))->body())
            ->toContain('<error>');
    });

    test(
        'publish a profile in the search for the simulator that holds the secure session of the avatar, and for no one else',
        function () {
            $accounts = test_pdo();
            $accounts->exec('CREATE TABLE UserAccounts (PrincipalID CHAR(36), UserFlags INT)');
            $accounts->exec('CREATE TABLE Presence (UserID CHAR(36), SecureSessionID CHAR(36))');
            $avatar = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';
            $session = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';
            $accounts->exec("INSERT INTO UserAccounts VALUES ('$avatar', 6)");
            $accounts->exec("INSERT INTO Presence VALUES ('$avatar', '$session')");
            $rpc = new XmlRpc(
                (new SearchQuery(
                    search_database(),
                    'regionsregister',
                    accounts: $accounts,
                    presence: $accounts,
                ))->methods(),
            );

            $shown = rpc_call($rpc, 'avatar_publish_update', [
                'avatar_id' => $avatar,
                'secure_session_id' => $session,
                'allow_publish' => 'true',
            ]);
            $flags = $accounts->get_var('SELECT UserFlags FROM UserAccounts');
            $hidden = rpc_call($rpc, 'avatar_publish_update', [
                'avatar_id' => $avatar,
                'secure_session_id' => $session,
                'allow_publish' => 'false',
            ]);
            $forged = rpc_call($rpc, 'avatar_publish_update', [
                'avatar_id' => $avatar,
                'secure_session_id' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
                'allow_publish' => 'true',
            ]);
            $invalid = rpc_call($rpc, 'avatar_publish_update', ['avatar_id' => 'nobody']);

            expect($shown['data']['success'])
                ->toBeTrue()
                ->and($flags)
                ->toBe(7)
                ->and($accounts->get_var('SELECT UserFlags FROM UserAccounts'))
                ->toBe(6)
                ->and($hidden['data']['success'])
                ->toBeTrue()
                ->and($forged['status'])
                ->toBe(403)
                ->and($invalid['status'])
                ->toBe(400);
        },
    );
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');

/** The XML of a request to a method. */
function rpc_xml(string $method, array $request): string
{
    return (new Request($method, [(new Encoder())->encode($request)]))->serialize();
}

describe('The guide of a grid that has no list', function () {
    /** The places of two grids in a search shared by both. */
    function guide_database(): OpenSim\Data\OSPDO
    {
        $db = search_database();
        $db->exec(
            "INSERT INTO regionsregister VALUES ('Welcome', 'r1', '1', 'http://sim.example.org:9000/', 'Ada', 'o', 'http://grid.example.org:8002'), " .
                "('Far', 'r2', '2', 'http://other.example.org:9000/', 'Bob', 'o', 'http://other.example.org:8002')",
        );
        $parcel = static fn(
            string $uuid,
            string $name,
            string $region,
            string $point,
            int $dwell,
            string $gk,
        ): string => "('$uuid', '$region', '$name', '$point', '', '', 'true', 'true', 'true', $dwell, 'info', 'PG', '$gk', NULL)";
        $db->exec(
            'INSERT INTO parcels VALUES ' .
                implode(', ', [
                    $parcel('p1', 'Rose garden', 'r1', '128/128/25', 30, 'http://grid.example.org:8002'),
                    $parcel('p2', 'Library', 'r1', '64,64,22', 5, 'http://grid.example.org:8002'),
                    $parcel('p3', 'Market', 'r1', '10/10/30', 10, 'http://grid.example.org:8002'),
                    $parcel('p4', 'Elsewhere', 'r2', '', 50, 'http://other.example.org:8002'),
                ]),
        );
        $db->exec(
            "INSERT INTO popularplaces VALUES ('p1', 'Rose garden', 30, 'info', 0, 'PG', 'http://grid.example.org:8002'), " .
                "('p3', 'Market', 10, 'info', 0, 'PG', 'http://grid.example.org:8002'), " .
                "('p4', 'Elsewhere', 50, 'info', 0, 'PG', 'http://other.example.org:8002')",
        );

        return $db;
    }

    test(
        'lists the popular places and the places shown in search, the first ten of each, of the grid asked',
        function () {
            $db = guide_database();

            $ours = (new OpenSim\Services\DefaultDestinations(
                $db,
                'regionsregister',
                'grid.example.org:8002',
                'http://grid.example.org:8002',
            ))->lists();
            $shared = (new OpenSim\Services\DefaultDestinations(
                $db,
                'regionsregister',
                'grid.example.org:8002',
            ))->lists();

            expect($ours)
                ->toBe([
                    'Popular places' => [
                        ['name' => 'Rose garden', 'url' => 'grid.example.org:8002/Welcome/128/128/25'],
                        ['name' => 'Market', 'url' => 'grid.example.org:8002/Welcome/10/10/30'],
                    ],
                    'Places in the search' => [
                        ['name' => 'Library', 'url' => 'grid.example.org:8002/Welcome/64/64/22'],
                    ],
                ])
                ->and($shared['Popular places'][0])
                ->toBe(['name' => 'Elsewhere', 'url' => 'other.example.org:8002/Far'])
                ->and(count($shared['Popular places']))
                ->toBe(3);
        },
    );

    test('keep ten of each section', function () {
        $db = search_database();
        $db->exec("INSERT INTO regionsregister VALUES ('Welcome', 'r1', '1', 'http://sim:9000/', 'Ada', 'o', '')");
        for ($i = 1; $i <= 25; $i++) {
            $db->exec(
                "INSERT INTO parcels VALUES ('p$i', 'r1', 'Place " .
                    str_pad((string) $i, 2, '0', STR_PAD_LEFT) .
                    "', '', '', '', 'true', 'true', 'true', $i, 'info', 'PG', '', NULL)",
            );
            $i <= 12 && $db->exec("INSERT INTO popularplaces VALUES ('p$i', 'Place $i', $i, 'info', 0, 'PG', '')");
        }

        $lists = (new OpenSim\Services\DefaultDestinations($db, 'regionsregister', 'grid.example.org:8002'))->lists();

        expect(count($lists['Popular places']))
            ->toBe(10)
            ->and(count($lists['Places in the search']))
            ->toBe(10)
            ->and($lists['Popular places'][0]['name'])
            ->toBe('Place 12')
            ->and($lists['Places in the search'][0]['name'])
            ->toBe('Place 01');
    });

    test('is nothing when the search knows nothing, or has no tables', function () {
        $empty = (new OpenSim\Services\DefaultDestinations(
            search_database(),
            'regionsregister',
            'grid.example.org:8002',
        ))->lists();
        $none = (new OpenSim\Services\DefaultDestinations(
            test_pdo(),
            'regionsregister',
            'grid.example.org:8002',
        ))->lists();

        expect($empty)->toBe([])->and($none)->toBe([]);
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');
