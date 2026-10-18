<?php

use OpenSim\Text\Encoding;
use OpenSim\Text\Format;
use OpenSim\Text\Identifier;

describe('identifiers', function () {
    test('are lower case, accents out, one separator', function () {
        expect(Identifier::id('Grand Place — Été'))
            ->toBe('grand_place_ete')
            ->and(Identifier::id('A--B  C'))
            ->toBe('a-b_c')
            ->and(Identifier::id(''))
            ->toBeFalse()
            ->and(Identifier::version('OpenSim 0.9.3.1 dev'))
            ->toBe('opensim_0.9.3.1_dev');
    })->skip(fn() => !function_exists('transliterator_transliterate'), 'ext-intl is missing');
});

describe('encodings', function () {
    test('text that is not UTF-8 is read as Latin-1', function () {
        expect(Encoding::utf8("Caf\xE9"))->toBe('Café')->and(Encoding::utf8('Café'))->toBe('Café');
    });
});

describe('formats', function () {
    test('a format that fails is given back', function () {
        expect(Format::sprintfSafe('%s and %s', 'a', 'b'))->toBe('a and b');
        $result = @Format::sprintfSafe('%s and %s', 'a');
        expect($result)->toBe('%s and %s');
    });
});
