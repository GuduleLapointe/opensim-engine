<?php

declare(strict_types=1);

namespace OpenSim\Config;

/**
 * The layouts an install of OpenSimulator can have, and the default folders of each.
 */
final class DirectoryLayout
{
    public const SYSTEM = 'system';
    public const BUNDLED = 'bundled';
    public const FLAT = 'flat';

    /** @var array<string,string> layout => what it is, as a menu shows it */
    public const LABELS = [
        self::SYSTEM => 'System — /etc/opensim, /var/lib/opensim, /usr/local/share/opensim',
        self::BUNDLED => 'Bundled — everything under one base directory (e.g. /opt/opensim)',
        self::FLAT => "Flat — OpenSim's default, all files in the core directory",
    ];

    /** Whether this layout needs a base directory to be chosen. */
    public static function needsBase(string $layout): bool
    {
        return $layout !== self::SYSTEM;
    }

    /** The base directory proposed for a layout, empty when it has none to propose (flat: where the core is). */
    public static function defaultBase(string $layout): string
    {
        return match ($layout) {
            self::SYSTEM => '/usr/local/share/opensim',
            self::BUNDLED => '/opt/opensim',
            default => '',
        };
    }

    /**
     * The folders of a layout, named as in opensim.conf: CoreRoot, CoreDirectory (when the version is known), EtcRoot, VarRoot,
     * LogsRoot, CacheRoot, DataRoot.
     *
     * @param string $base the base directory of the bundled and flat layouts
     * @param string $version the version of the core, for its directory
     * @return array<string,string>
     */
    public static function roots(string $layout = self::SYSTEM, string $base = '', string $version = ''): array
    {
        $roots = match ($layout) {
            self::SYSTEM => [
                'CoreRoot' => '/usr/local/share/opensim',
                'CoreDirectory' => $version !== '' ? "/usr/local/share/opensim/$version" : '',
                'EtcRoot' => '/etc/opensim',
                'VarRoot' => '/var/lib/opensim',
                'LogsRoot' => '/var/log/opensim',
                'CacheRoot' => '/var/cache/opensim',
                'DataRoot' => '/var/lib/opensim/data',
            ],
            self::BUNDLED => [
                'CoreRoot' => "$base/core",
                'CoreDirectory' => $version !== '' ? "$base/core/opensim-$version" : '',
                'EtcRoot' => "$base/etc",
                'VarRoot' => "$base/var",
                'LogsRoot' => "$base/var/logs",
                'CacheRoot' => "$base/var/cache",
                'DataRoot' => "$base/var/data",
            ],
            self::FLAT => $version === ''
                ? []
                : array_fill_keys(
                        ['EtcRoot', 'VarRoot', 'LogsRoot', 'CacheRoot', 'DataRoot'],
                        "$base/opensim-$version/bin",
                    ) + ['CoreRoot' => "$base/opensim-$version", 'CoreDirectory' => "$base/opensim-$version"],
            default => throw new \InvalidArgumentException("unknown directory layout '$layout'"),
        };

        return array_filter($roots, static fn(string $value): bool => $value !== '');
    }
}
