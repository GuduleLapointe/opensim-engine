<?php

declare(strict_types=1);

namespace OpenSim\Assets;

/**
 * Draws a text with ImageMagick (the extension `imagick`): the text centered on a 1024 pixels square, in the font, the size and the
 * colors asked. A font the machine does not have is replaced by DejaVu-Sans, or by the first font the machine has when it has not that either.
 */
final class ImagickTextRenderer implements TextRendererInterface
{
    public const SIDE = 1024;
    public const FALLBACK_FONT = 'DejaVu-Sans';

    public function render(string $text, string $font, int $size, string $color, string $background): ?string
    {
        if (!extension_loaded('imagick')) {
            return null;
        }
        try {
            $draw = new \ImagickDraw();
            // A font the machine does not have is replaced by the fallback, else by the default font of ImageMagick
            $fonts = $this->fonts();
            $name = in_array($font, $fonts, true)
                ? $font
                : (in_array(self::FALLBACK_FONT, $fonts, true)
                    ? self::FALLBACK_FONT
                    : ($fonts[0] ?? null));
            $name !== null && $draw->setFont($name);
            $draw->setFontSize($size);
            $draw->setFillColor($color);
            $draw->setTextAlignment(\Imagick::ALIGN_CENTER);
            $image = new \Imagick();
            $image->newImage(self::SIDE, self::SIDE, $background);
            $image->setImageFormat('png');
            $metrics = $image->queryFontMetrics($draw, $text);
            $image->annotateImage($draw, self::SIDE / 2, self::SIDE / 2 + $metrics['textHeight'] / 4, 0, $text);
            $blob = $image->getImageBlob();
            $image->clear();
        } catch (\ImagickException | \ImagickDrawException | \ImagickPixelException $error) {
            throw new \InvalidArgumentException(
                "the text cannot be drawn with the color '$color' on '$background': " . $error->getMessage(),
            );
        }

        return $blob !== '' ? $blob : null;
    }

    public function fonts(): array
    {
        return extension_loaded('imagick') ? array_values(\Imagick::queryFonts('*')) : [];
    }
}
