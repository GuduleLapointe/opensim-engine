<?php
/**
 * What the engine does with nothing but its arguments: destinations and teleport links, UUIDs, the text the
 * search tables take.
 */

use OpenSim\Economy\Currency;
use OpenSim\Hypergrid\Destination;
use OpenSim\Text\Encoding;
use OpenSim\Text\Uuid;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

const TP_DESTINATION = 'hg.example.org:8002:My Region/128/64/22';

describe('Destinations', function () {
    test('keep a position that has a zero in it', function () {
        expect(Destination::parse('grid.example:8002:Plaza/0/128/30')['pos'])
            ->toBe('0/128/30')
            ->and(Destination::format('grid.example:8002:Plaza/0/0/30', TPLINK_HOP))
            ->toBe('hop://grid.example:8002/Plaza/0/0/30');
    });

    test('are read from the forms people write them', function (string $url) {
        $parsed = Destination::parse($url);

        expect($parsed['gatekeeper'])
            ->toBe('http://grid.example:8002')
            ->and($parsed['region'])
            ->toBe('Region Name')
            ->and($parsed['pos'])
            ->toBe('1/2/3')
            ->and(Destination::uri($url))
            ->toBe('grid.example:8002/Region Name/1/2/3');
    })->with([
        'with colons' => 'grid.example:8002:Region Name/1/2/3',
        'with a space' => 'grid.example:8002 Region Name/1/2/3',
        'with pluses' => 'grid.example:8002+Region+Name/1/2/3',
        'with a scheme' => 'http://grid.example:8002/Region Name/1/2/3',
        'with a scheme and colons' => 'http://grid.example:8002:Region Name/1/2/3',
        'as a viewer link' => 'secondlife://grid.example:8002/Region%20Name/1/2/3',
    ]);

    test('keep the region alone, and an address alone', function () {
        // Every key is there, empty when the address has nothing for it, for who extracts them
        expect(Destination::parse('grid.example:8002:Plaza'))
            ->toMatchArray(['region' => 'Plaza', 'gatekeeper' => 'http://grid.example:8002', 'pos' => '', 'key' => 'grid.example:8002/plaza'])
            ->and(Destination::parse('Plaza/10/20/30'))
            ->toMatchArray(['region' => 'Plaza', 'pos' => '10/20/30', 'gatekeeper' => '', 'host' => '']);
    });

    test('get the default gatekeeper when they have none', function () {
        expect(Destination::parse('Plaza/1/2/3', 'http://login.example:8002')['gatekeeper'])->toBe(
            'http://login.example:8002',
        );
    });
});

describe('Teleport links', function () {
    test('are made in each format', function (int $format, string $link) {
        expect(Destination::format(TP_DESTINATION, $format))->toBe($link);
    })->with([
        'text' => [TPLINK_TXT, 'hg.example.org:8002 My Region/128/64/22'],
        'viewer link' => [TPLINK_HG, 'secondlife://hg.example.org:8002%20My%20Region/128/64/22'],
        'viewer v3 link' => [TPLINK_V3HG, 'secondlife://http|!!hg.example.org|8002%20My%20Region/128/64/22'],
        'hop' => [TPLINK_HOP, 'hop://hg.example.org:8002/My+Region/128/64/22'],
        'teleport link' => [TPLINK_APPTP, 'secondlife:///app/teleport/hg.example.org:8002+My+Region/128/64/22'],
    ]);

    test('leave the position out when it is out of a local region', function () {
        expect(Destination::format('hg.example.org:8002:My Region/300/64/22', TPLINK_HG))->toBe(
            'secondlife://hg.example.org:8002%20My%20Region',
        );
    });

    test('are several, one per line, or an array', function () {
        $both = Destination::format(TP_DESTINATION, TPLINK_TXT + TPLINK_HOP);
        $array = Destination::format(TP_DESTINATION, TPLINK_TXT + TPLINK_HOP + TPLINK_ARRAY);

        expect($both)
            ->toBe("hg.example.org:8002 My Region/128/64/22\nhop://hg.example.org:8002/My+Region/128/64/22")
            ->and($array)
            ->toBe([
                TPLINK_TXT => 'hg.example.org:8002 My Region/128/64/22',
                TPLINK_HOP => 'hop://hg.example.org:8002/My+Region/128/64/22',
            ]);
    });

    test('are none for nothing', function () {
        expect(Destination::format('', TPLINK_HG))
            ->toBe('')
            ->and(Destination::format('', TPLINK_HG + TPLINK_ARRAY))
            ->toBe([]);
    });
});

describe('UUIDs', function () {
    test('are told from other texts', function () {
        expect((bool) Uuid::isValid('9d315475-daf9-4e31-a5e6-d787ddfcbef6'))
            ->toBeTrue()
            ->and((bool) Uuid::isValid('9D315475-DAF9-4E31-A5E6-D787DDFCBEF6'))
            ->toBeTrue()
            ->and((bool) Uuid::isValid('9d315475-daf9-4e31-a5e6'))
            ->toBeFalse()
            ->and((bool) Uuid::isValid("9d315475-daf9-4e31-a5e6-d787ddfcbef6'; DROP TABLE x"))
            ->toBeFalse()
            ->and((bool) Uuid::isValid('nothing'))
            ->toBeFalse();
    });

    test('null and the null key are valid only when asked', function () {
        $null = '00000000-0000-0000-0000-000000000000';

        expect(Uuid::isValid(null))
            ->toBeFalse()
            ->and(Uuid::isValid(null, true))
            ->toBeTrue()
            ->and(Uuid::isValid($null, true))
            ->toBeTrue();
    });

    test('are checked as version 4 when it is asked', function () {
        expect((bool) Uuid::isValid('9d315475-daf9-4e31-a5e6-d787ddfcbef6', false, true))
            ->toBeTrue()
            ->and((bool) Uuid::isValid('9d315475-daf9-1e31-a5e6-d787ddfcbef6', false, true))
            ->toBeFalse();
    });
});

test('search tables take Latin-1: what it cannot hold becomes a question mark', function () {
    expect(Encoding::latin1Only('Café Zoë'))
        ->toBe('Café Zoë')
        ->and(Encoding::latin1Only('Price: 5€ 日本'))
        ->toBe('Price: 5? ??');
});

describe('session of an avatar', function () {
    $presence = function (array $rows): object {
        return new class($rows) {
            public function __construct(private array $rows) {}

            public function prepareAndExecute($sql, $params = [])
            {
                $rows = $this->rows[$params['user']] ?? [];

                return new class($rows) {
                    public function __construct(private array $rows) {}

                    public function fetch()
                    {
                        return $this->rows ?: false;
                    }
                };
            }
        };
    };

    test('is read from the presence service: where the avatar is and its session IDs', function () use ($presence) {
        $avatar = 'aaaaaaaa-0000-4000-8000-000000000001';
        $GLOBALS['OpenSimDB'] = $presence([$avatar => ['rrrrrrrr-0000-4000-8000-000000000003', 'ssssssss-0000-4000-8000-000000000004', 'xxxxxxxx-0000-4000-8000-000000000005']]);

        expect(Currency::avatarSession($avatar))->toBe([
            'regionID' => 'rrrrrrrr-0000-4000-8000-000000000003',
            'sessionID' => 'ssssssss-0000-4000-8000-000000000004',
            'secureID' => 'xxxxxxxx-0000-4000-8000-000000000005',
        ]);
    });

    test('is empty for an avatar who is not in the grid, and null for what is not an avatar', function () use ($presence) {
        $GLOBALS['OpenSimDB'] = $presence([]);

        expect(Currency::avatarSession('bbbbbbbb-0000-4000-8000-000000000002'))
            ->toBe([])
            ->and(Currency::avatarSession('not-an-avatar'))
            ->toBeNull();
    });
});

describe('Region urls', function () {
    test('are written back from the parts', function () {
        $parts = Destination::parse('hg.example.org:8002:My Region/128/64/22');

        expect(Destination::regionUrl($parts))->toBe('http://hg.example.org:8002:My Region/128/64/22')
            ->and(Destination::regionUrl(['gatekeeper' => 'http://hg.example.org:8002']))->toBe('http://hg.example.org:8002')
            ->and(Destination::regionUrl('not an array'))->toBeFalse();
    });
});
