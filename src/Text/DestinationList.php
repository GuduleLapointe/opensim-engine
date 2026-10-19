<?php

declare(strict_types=1);

namespace OpenSim\Text;

use OpenSim\Network\Http;

/**
 * The destinations of a guide, written as lines "Name|Region URL" in a file or at a URL; a name alone starts a category. The same
 * lines serve the boards of the scripts (a dash alone is a new column, a pipe alone an empty row: layout hints, not destinations),
 * and the older format that gives the grid and the region in the 4th and 5th columns. A third column of `x,y,z` is the place in the
 * region.
 */
final class DestinationList
{
    /**
     * The text of a source: a URL, or a file (a relative path is looked for in `$folder`).
     *
     * @param ?\Closure(string):?string $http the body of an address, null when it does not answer (default: curl)
     * @return ?string null when there is no source or it cannot be read
     */
    public static function read(?string $source, ?string $folder = null, ?\Closure $http = null): ?string
    {
        $source = trim((string) $source);
        if ($source === '') {
            return null;
        }
        if (filter_var($source, FILTER_VALIDATE_URL) !== false && preg_match('#^https?://#i', $source) === 1) {
            return $http !== null ? $http($source) : Http::get($source);
        }
        $path = $source[0] === '/' || $folder === null || $folder === '' ? $source : rtrim($folder, '/') . "/$source";
        $text = is_file($path) ? @file_get_contents($path) : false;

        return $text === false ? null : $text;
    }

    /**
     * The destinations of a text, by category, in the order of the text.
     *
     * @param ?string $first the title of the destinations that come before any category
     * @return array<string,list<array{name:string,url:string}>>
     */
    public static function parse(string $text, ?string $first = null): array
    {
        $destinations = [];
        $category = $first ?? _('Destinations Guide');
        foreach (explode("\n", str_replace("\r", '', $text)) as $line) {
            $line = trim($line);
            // Comments, empty lines, the layout hints of the boards
            if ($line === '' || $line[0] === '#' || str_starts_with($line, '//') || $line === '-') {
                continue;
            }
            $parts = array_map('trim', explode('|', rtrim($line, '|')));
            if ($parts[0] === '') {
                continue;
            }
            $name = $parts[0];
            if (($parts[3] ?? '') !== '') {
                // The older format: the grid and the region are the 4th and 5th columns
                $url = $parts[3] . '/' . ($parts[4] ?? '');
            } else {
                $url = $parts[1] ?? '';
                // The place in the region: 128,128,30
                if (
                    $url !== '' &&
                    preg_match('/^\d+(?:\.\d+)?,\d+(?:\.\d+)?(?:,\d+(?:\.\d+)?)?$/', $parts[2] ?? '') === 1
                ) {
                    $url .= '/' . str_replace(',', '/', $parts[2]);
                }
            }
            if ($url === '') {
                $category = $name;
            } else {
                $destinations[$category][] = ['name' => $name, 'url' => $url];
            }
        }

        return $destinations;
    }
}
