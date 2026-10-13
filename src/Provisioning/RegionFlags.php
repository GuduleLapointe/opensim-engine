<?php

namespace OpenSim\Provisioning;

use OpenSim\Config\ConfigReader;
use OpenSim\Config\IniEditor;

/**
 * The roles of a region in the grid, given by Robust to a region when it
 * registers, from a key of [GridService]: Region_<Name> = "DefaultRegion, ...".
 */
final class RegionFlags
{
    public const DEFAULT = 'DefaultRegion';
    public const DEFAULT_HG = 'DefaultHGRegion';
    public const FALLBACK = 'FallbackRegion';

    /**
     * The roles a grid can give, in the order they are offered: the default
     * region, the one of the visitors of other grids (Hypergrid only), the
     * one visitors fall back to.
     *
     * @return list<string>
     */
    public static function roles(bool $hypergrid): array
    {
        return $hypergrid ? [self::DEFAULT, self::DEFAULT_HG, self::FALLBACK] : [self::DEFAULT, self::FALLBACK];
    }

    /**
     * The roles the regions of a Robust config have: the values of its Region_<Name> keys, read as Robust reads them (quotes around
     * the value or not, a comment after it, the examples that are commented left out).
     *
     * @return list<string>
     */
    public static function found(string $text): array
    {
        $found = [];
        foreach (ConfigReader::parse($text) as $section) {
            foreach ($section as $key => $value) {
                if (!str_starts_with((string) $key, 'Region_')) {
                    continue;
                }
                foreach (explode(',', (string) $value) as $flag) {
                    $flag = trim($flag);
                    $flag === '' || $found[$flag] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Give roles to a region in the Robust config, the ones it has already kept. The
     * line goes where the config shows an example of it, and a role given to another
     * region is not asked twice.
     *
     * @param list<string> $roles
     */
    public static function give(string $robustIni, string $region, array $roles): void
    {
        $ini = IniEditor::load($robustIni);
        $key = RegionName::configKey($region);
        $flags = array_filter(array_map('trim', explode(',', (string) $ini->get('GridService', $key))));
        $flags = array_values(array_unique([...$flags, ...$roles, 'Persistent']));
        $ini->set('GridService', $key, '"' . implode(', ', $flags) . '"', 'Region_');
        $ini->save($robustIni);
    }
}
