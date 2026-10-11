<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

/**
 * Where the files an application ships are (the presets, the original MoneyServer.ini...): the application that uses the
 * engine says where its own root is, the engine looks there, then in its own.
 *
 *     ResourceRegistry::add(__DIR__);                         // the root of the application
 *     ResourceRegistry::directories('share/presets');         // its share/presets, the engine's, the ones that exist
 */
final class ResourceRegistry
{
    /** @var list<string> */
    private static array $roots = [];

    /** Add the root of an application, looked in before the ones added earlier and the engine's. */
    public static function add(string $root): void
    {
        $root = rtrim($root, '/');
        self::$roots = [$root, ...array_values(array_diff(self::$roots, [$root]))];
    }

    /** Forget the roots added. */
    public static function reset(): void
    {
        self::$roots = [];
    }

    /** @return list<string> the roots, the last added first, the one of the application (composer's root package) and the engine's last */
    public static function roots(): array
    {
        $application = class_exists(\Composer\InstalledVersions::class) ? (\Composer\InstalledVersions::getRootPackage()['install_path'] ?? '') : '';

        return array_values(array_unique(array_filter([...self::$roots, $application !== '' ? (realpath($application) ?: rtrim($application, '/')) : '', dirname(__DIR__, 2)])));
    }

    /**
     * The folders of a path relative to a root that exist.
     *
     * @return list<string>
     */
    public static function directories(string $relative): array
    {
        $found = [];
        foreach (self::roots() as $root) {
            if (is_dir("$root/$relative")) {
                $found[] = "$root/$relative";
            }
        }

        return $found;
    }
}
