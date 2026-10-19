<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Assets\ConverterInterface;

/**
 * An image of the grid, whatever its type: a texture (the grid keeps it as JPEG 2000), a TGA, a JPEG. It is converted to an image a
 * web page shows (png, jpg...); an image that is in the format asked is given as it is.
 */
final class Image extends Asset
{
    /** The formats a page can be given, with their content type. */
    public const FORMATS = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    /** The format a page is given when it asks none. */
    public const DEFAULT_FORMAT = 'jpg';

    /**
     * The picture in a format of the web, null when the data is missing or cannot be converted. An image already in the format asked
     * is given as it is, unless it is to be resized.
     *
     * @param string $format one of FORMATS
     * @param ?int $width the width in pixels to resize to, the height following
     */
    public function render(
        ConverterInterface $converter,
        string $format = self::DEFAULT_FORMAT,
        ?int $width = null,
    ): ?string {
        $data = $this->data();
        if (!isset(self::FORMATS[$format]) || $data === null) {
            return null;
        }

        return $width === null && $this->mime() === self::FORMATS[$format]
            ? $data
            : $converter->convert($data, $format, $width);
    }
}
