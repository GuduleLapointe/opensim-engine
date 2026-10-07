<?php
/**
 * The functions of the engine that need nothing but their arguments: destinations and teleport links, UUIDs, the text the
 * search tables take.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';

const TP_DESTINATION = 'hg.example.org:8002:My Region/128/64/22';

describe('Destinations', function () {
    test('keep a position that has a zero in it', function () {
        expect(opensim_parse_url('grid.example:8002:Plaza/0/128/30')['pos'])
            ->toBe('0/128/30')
            ->and(opensim_format_tp('grid.example:8002:Plaza/0/0/30', TPLINK_HOP))
            ->toBe('hop://grid.example:8002/Plaza/0/0/30');
    });

    test('are read from the forms people write them', function (string $url) {
        $parsed = opensim_parse_url($url);

        expect($parsed['gatekeeper'])
            ->toBe('http://grid.example:8002')
            ->and($parsed['region'])
            ->toBe('Region Name')
            ->and($parsed['pos'])
            ->toBe('1/2/3')
            ->and(opensim_uri($url))
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
        expect(opensim_parse_url('grid.example:8002:Plaza'))
            ->toMatchArray(['region' => 'Plaza', 'gatekeeper' => 'http://grid.example:8002', 'pos' => '', 'key' => 'grid.example:8002/plaza'])
            ->and(opensim_parse_url('Plaza/10/20/30'))
            ->toMatchArray(['region' => 'Plaza', 'pos' => '10/20/30', 'gatekeeper' => '', 'host' => '']);
    });

    test('get the default gatekeeper when they have none', function () {
        expect(opensim_parse_url('Plaza/1/2/3', 'http://login.example:8002')['gatekeeper'])->toBe(
            'http://login.example:8002',
        );
    });
});

describe('Teleport links', function () {
    test('are made in each format', function (int $format, string $link) {
        expect(opensim_format_tp(TP_DESTINATION, $format))->toBe($link);
    })->with([
        'text' => [TPLINK_TXT, 'hg.example.org:8002 My Region/128/64/22'],
        'viewer link' => [TPLINK_HG, 'secondlife://hg.example.org:8002%20My%20Region/128/64/22'],
        'viewer v3 link' => [TPLINK_V3HG, 'secondlife://http|!!hg.example.org|8002%20My%20Region/128/64/22'],
        'hop' => [TPLINK_HOP, 'hop://hg.example.org:8002/My+Region/128/64/22'],
        'teleport link' => [TPLINK_APPTP, 'secondlife:///app/teleport/hg.example.org:8002+My+Region/128/64/22'],
    ]);

    test('leave the position out when it is out of a local region', function () {
        expect(opensim_format_tp('hg.example.org:8002:My Region/300/64/22', TPLINK_HG))->toBe(
            'secondlife://hg.example.org:8002%20My%20Region',
        );
    });

    test('are several, one per line, or an array', function () {
        $both = opensim_format_tp(TP_DESTINATION, TPLINK_TXT + TPLINK_HOP);
        $array = opensim_format_tp(TP_DESTINATION, TPLINK_TXT + TPLINK_HOP + TPLINK_ARRAY);

        expect($both)
            ->toBe("hg.example.org:8002 My Region/128/64/22\nhop://hg.example.org:8002/My+Region/128/64/22")
            ->and($array)
            ->toBe([
                TPLINK_TXT => 'hg.example.org:8002 My Region/128/64/22',
                TPLINK_HOP => 'hop://hg.example.org:8002/My+Region/128/64/22',
            ]);
    });

    test('are none for nothing', function () {
        expect(opensim_format_tp('', TPLINK_HG))
            ->toBe('')
            ->and(opensim_format_tp('', TPLINK_HG + TPLINK_ARRAY))
            ->toBe([]);
    });
});

describe('UUIDs', function () {
    test('are told from other texts', function () {
        expect((bool) is_uuid('9d315475-daf9-4e31-a5e6-d787ddfcbef6'))
            ->toBeTrue()
            ->and((bool) is_uuid('9D315475-DAF9-4E31-A5E6-D787DDFCBEF6'))
            ->toBeTrue()
            ->and((bool) is_uuid('9d315475-daf9-4e31-a5e6'))
            ->toBeFalse()
            ->and((bool) is_uuid("9d315475-daf9-4e31-a5e6-d787ddfcbef6'; DROP TABLE x"))
            ->toBeFalse()
            ->and((bool) is_uuid('nothing'))
            ->toBeFalse();
    });

    test('null and the null key are valid only when asked', function () {
        $null = '00000000-0000-0000-0000-000000000000';

        expect(is_uuid(null))
            ->toBeFalse()
            ->and(is_uuid(null, true))
            ->toBeTrue()
            ->and(is_uuid($null, true))
            ->toBeTrue();
    });

    test('are checked as version 4 when it is asked', function () {
        expect((bool) is_uuid('9d315475-daf9-4e31-a5e6-d787ddfcbef6', false, true))
            ->toBeTrue()
            ->and((bool) is_uuid('9d315475-daf9-1e31-a5e6-d787ddfcbef6', false, true))
            ->toBeFalse();
    });
});

test('the search tables take Latin-1: what it cannot hold becomes a question mark', function () {
    expect(opensim_latin1_only('Café Zoë'))
        ->toBe('Café Zoë')
        ->and(opensim_latin1_only('Price: 5€ 日本'))
        ->toBe('Price: 5? ??');
});
