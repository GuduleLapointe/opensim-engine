<?php

declare(strict_types=1);

namespace OpenSim\Assets;

/**
 * Converts images with ImageMagick (the extension `imagick`, with its JPEG 2000 delegate for the textures of the grid).
 */
final class ImagickConverter implements ConverterInterface
{
    /** Whether the machine can read the textures of a grid. */
    public static function available(): bool
    {
        return extension_loaded('imagick') && in_array('JP2', \Imagick::queryFormats('JP2'), true);
    }

    public function convert(string $data, string $format): ?string
    {
        if (!extension_loaded('imagick') || $data === '') {
            return null;
        }
        try {
            $image = new \Imagick();
            $image->readImageBlob($data);
            $image->setImageFormat($format);
            $blob = $image->getImageBlob();
        } catch (\ImagickException) {
            return null;
        }

        return $blob !== '' ? $blob : null;
    }
}
