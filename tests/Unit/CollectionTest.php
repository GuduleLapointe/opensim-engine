<?php

declare(strict_types=1);

/**
 * What a list gives back: its items, the total before the pages, the pages and the forms an application takes it in.
 */

use OpenSim\Data\Collection;

describe('A collection', function () {
    test('has a first, a last, a count, and none of them when it is empty', function () {
        $list = new Collection([['name' => 'a'], ['name' => 'b'], ['name' => 'c']]);
        $none = new Collection();

        expect($list->first())->toBe(['name' => 'a'])->and($list->last())->toBe(['name' => 'c'])
            ->and($list->count())->toBe(3)->and(count($list))->toBe(3)->and($list->isEmpty())->toBeFalse()
            ->and($none->first())->toBeNull()->and($none->last())->toBeNull()->and($none->isEmpty())->toBeTrue()
            ->and(iterator_to_array($list))->toHaveCount(3);
    });

    test('knows its total before the pages, and its pages', function () {
        $page = new Collection([['n' => 1], ['n' => 2]], ['total' => 5, 'page' => 2, 'perPage' => 2]);

        expect($page->count())->toBe(2)->and($page->total())->toBe(5)->and($page->pages())->toBe(3)->and($page->page())->toBe(2)
            ->and((new Collection([['n' => 1]]))->total())->toBe(1)->and((new Collection([['n' => 1]]))->pages())->toBe(1);
    });

    test('is an array, a JSON and a CSV', function () {
        $list = new Collection([['name' => 'Ada "A" Love', 'note' => null, 'ok' => true, 'tags' => ['x']], ['name' => 'Bob, Root', 'ok' => false]], [
            'columns' => [['name' => 'name', 'label' => 'Name'], ['name' => 'note', 'label' => 'Note'], ['name' => 'ok', 'label' => 'Ok'], ['name' => 'tags', 'label' => 'Tags']],
        ]);

        expect($list->array())->toHaveCount(2)
            ->and(json_decode($list->json(), true)[0]['name'])->toBe('Ada "A" Love')
            ->and($list->csv())->toBe("name,note,ok,tags\n\"Ada \"\"A\"\" Love\",,1,\"[\"\"x\"\"]\"\n\"Bob, Root\",,0,\n")
            ->and(json_encode($list))->toBe($list->json());
    });

    test('takes the names of its columns from the first item when it has none', function () {
        expect((new Collection([['a' => 1, 'b' => 2]]))->csv())->toBe("a,b\n1,2\n")
            ->and((new Collection())->csv())->toBe("\n");
    });
});
