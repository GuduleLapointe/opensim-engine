<?php

declare(strict_types=1);

namespace OpenSim\Assets;

/**
 * Makes the picture of a text, the texture of a prim.
 */
interface TextRendererInterface
{
    /**
     * @return ?string the PNG of the text, square, null when the machine cannot draw
     * @throws \InvalidArgumentException when the color or the background is not one
     */
    public function render(string $text, string $font, int $size, string $color, string $background): ?string;

    /** @return list<string> the fonts the machine has */
    public function fonts(): array;
}
