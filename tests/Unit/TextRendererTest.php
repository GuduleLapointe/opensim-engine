<?php

declare(strict_types=1);

/**
 * The picture of a text, drawn with ImageMagick where the machine has it.
 */

use OpenSim\Assets\ImagickTextRenderer;

describe('The text renderer', function () {
    test('draws a text as a square PNG, in a font the machine has or in the fallback', function () {
        $renderer = new ImagickTextRenderer();

        $png = $renderer->render('Hello', 'Impact', 120, 'white', '#102030');
        $image = new Imagick();
        $image->readImageBlob($png);

        expect(substr($png, 1, 3))
            ->toBe('PNG')
            ->and([$image->getImageWidth(), $image->getImageHeight()])
            ->toBe([1024, 1024])
            ->and($renderer->render('Hello', 'No such font', 60, 'red', 'black'))
            ->toBeString()
            ->and($renderer->fonts())
            ->toBeArray();
    });

    test('refuses a color that is not one', function () {
        expect(fn() => (new ImagickTextRenderer())->render('x', 'Impact', 120, 'notacolor', 'black'))->toThrow(
            InvalidArgumentException::class,
            "color 'notacolor'",
        );
        expect(fn() => (new ImagickTextRenderer())->render('x', 'Impact', 120, 'white', 'notacolor'))->toThrow(
            InvalidArgumentException::class,
        );
    });
})->skip(fn() => !extension_loaded('imagick'), 'php-imagick is not loaded');
