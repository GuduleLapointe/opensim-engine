<?php

declare(strict_types=1);

namespace OpenSim\Assets;

/**
 * What turns the data of an asset into another format: the images of the grid (JPEG 2000) into what a page shows.
 */
interface ConverterInterface
{
    /**
     * @param string $data the bytes of the grid
     * @param string $format the format asked: png, jpg...
     * @param ?int $width the width in pixels to resize to, the height following; null keeps the size
     * @return ?string the converted bytes, null when they cannot be
     */
    public function convert(string $data, string $format, ?int $width = null): ?string;
}
