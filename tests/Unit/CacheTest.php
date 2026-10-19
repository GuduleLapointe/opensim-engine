<?php

declare(strict_types=1);

/**
 * What takes a search to know is searched once for the session, and once for a while in a store the requests share: the table of the
 * search database, else files.
 */

use OpenSim\Data\Cache;
use OpenSim\Data\DatabaseCacheStore;
use OpenSim\Data\FileCacheStore;
use OpenSim\Data\OSPDO;
use OpenSim\Testing\ClockFake;

/** A cache over a store of the kind, and the clock that tells it the time. */
function cache_over(string $kind, ?OSPDO $pdo = null): array
{
    Cache::flush();
    $clock = new ClockFake();
    $store =
        $kind === 'files'
            ? new FileCacheStore(test_tmp() . '/cache-' . bin2hex(random_bytes(4)))
            : new DatabaseCacheStore($pdo ?? test_pdo());

    return [new Cache($store, $clock), $clock, $store];
}

describe('the cache', function () {
    test('keeps in memory only what is given no time to live', function () {
        $store = new FileCacheStore(test_tmp() . '/cache-' . bin2hex(random_bytes(4)));
        $cache = new Cache($store, new ClockFake());
        $cache->set('session.only', ['a' => 1]);

        expect($cache->get('session.only'))
            ->toBe(['a' => 1])
            ->and($store->get('session.only', 1000))
            ->toBeNull()
            ->and($cache->get('nothing', 'else'))
            ->toBe('else');
    });

    test('holds a search that found nothing: it is not made again', function () {
        [$cache] = cache_over('files');
        $searched = 0;
        $search = function () use (&$searched) {
            $searched++;

            return null;
        };

        expect($cache->remember('lonely', $search))
            ->toBeNull()
            ->and($cache->remember('lonely', $search))
            ->toBeNull()
            ->and($cache->has('lonely'))
            ->toBeTrue()
            ->and($searched)
            ->toBe(1);
    });

    test('shares a memory between the caches of the same store', function () {
        [$cache, $clock, $store] = cache_over('files');
        $other = new Cache($store, $clock);
        $cache->set('shared', 'once');

        expect($other->get('shared'))->toBe('once');
    });

    test('forgets in memory and in the store', function () {
        [$cache, $clock, $store] = cache_over('files');
        $cache->set('gone', 'x', 60);
        $cache->forget('gone');

        expect($cache->has('gone'))->toBeFalse()->and($store->get('gone', 1000))->toBeNull();
    });

    test('reads the key as the table keeps it: readable, at most 200 characters', function () {
        [$cache, , $store] = cache_over('files');
        $cache->set('region url/with spaces:9000', 1, 60);
        $cache->set(str_repeat('k', 300), 2, 60);

        expect($store->get('region_url_with_spaces_9000', 1000)['value'])
            ->toBe(1)
            ->and($store->get(str_repeat('k', 200), 1000)['value'])
            ->toBe(2);
    });
});

describe('a store', function () {
    foreach (['files', 'database'] as $kind) {
        test("of $kind keeps for the time to live, then the value is gone", function () use ($kind) {
            [$cache, $clock, $store] = cache_over($kind);
            $cache->set('day', ['mood' => 'free'], 86400);
            $cache->set('for.ever', 'still', 0);

            $reader = new Cache($store, $clock);
            Cache::flush();

            expect($reader->get('day'))->toBe(['mood' => 'free'])->and($reader->get('for.ever'))->toBe('still');
            Cache::flush();
            $clock->now += 86401;
            expect($reader->get('day'))->toBeNull()->and($reader->get('for.ever'))->toBe('still');
        });
    }

    test('of the database writes the table of the helpers, JSON in the same columns', function () {
        $pdo = test_pdo();
        [$cache] = cache_over('database', $pdo);
        $cache->set('region.1', ['name' => 'Welcome'], 3600);
        $cache->set('region.1', ['name' => 'Renamed'], 3600);
        $row = $pdo->query('SELECT cache_key, cache_value, cache_expires FROM oshelpers_cache')->fetchAll(PDO::FETCH_ASSOC);

        expect($row)
            ->toHaveCount(1)
            ->and($row[0]['cache_key'])
            ->toBe('region.1')
            ->and(json_decode($row[0]['cache_value'], true))
            ->toBe(['name' => 'Renamed'])
            ->and((int) $row[0]['cache_expires'])
            ->toBe(1000 + 3600);
    });

    test('of the database reads what a helper wrote, a plain string included', function () {
        $pdo = test_pdo();
        [$cache, $clock, $store] = cache_over('database', $pdo);
        $cache->get('warm');
        $pdo->exec("INSERT INTO oshelpers_cache VALUES ('from.helper', '[1,2]', NULL), ('plain', 'not json', 0), ('past', '1', 5)");

        expect($cache->get('from.helper'))
            ->toBe([1, 2])
            ->and($cache->get('plain'))
            ->toBe('not json')
            ->and($cache->get('past'))
            ->toBeNull();
    });

    test('of the database that fails is left alone, the memory goes on', function () {
        $pdo = test_pdo();
        [$cache] = cache_over('database', $pdo);
        $cache->set('first', 1, 60);
        $pdo->exec('DROP TABLE oshelpers_cache');
        $pdo->exec('CREATE TABLE oshelpers_cache (other INT)');
        Cache::flush();

        expect($cache->get('first'))->toBeNull();
        $cache->set('second', 2, 60);
        expect($cache->get('second'))->toBe(2);
    });
})->skip(fn() => test_pdo() === null, 'TEST_DATABASE is not set in tests/.env');
