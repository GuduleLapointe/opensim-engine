<?php

namespace OpenSim\Provisioning\Web;

use OpenSim\Config\GridFileService;

/**
 * The web side of a grid. opensim-helpers read the official settings of the grid (Robust.ini, OpenSim.ini, the ini of its
 * simulators) in their own format, one source of truth: the setup writes nothing of them in another file.
 *
 * `Helpers.ini`, in the folder of the grid, only holds what has no official place, in a section [Helpers] (the source of the
 * guide, the search database, the theme...): the setup writes the commented template once, the rest is the operator's.
 */
final class HelpersConfig
{
    public const FILE = 'Helpers.ini';

    /** Where the helpers are, when the economy of the grid says nothing. */
    public const DEFAULT_PATH = '/helpers';

    public static function path(string $gridDir): string
    {
        return rtrim($gridDir, '/') . '/' . self::FILE;
    }

    /**
     * The files of a grid folder named like the file of the helpers in another case (`helpers.ini` of the first setups): a
     * system that does not tell the cases apart (macOS, Windows) cannot hold both. The only one is renamed, the second one is
     * kept aside as `<name>~`.
     *
     * @return list<array{0:string,1:string}> the names it had and the names it has now
     */
    public static function unifyCase(string $gridDir): array
    {
        $gridDir = rtrim($gridDir, '/');
        $variants = array_values(
            array_filter(
                scandir($gridDir) ?: [],
                static fn(string $name): bool => $name !== self::FILE && strcasecmp($name, self::FILE) === 0,
            ),
        );
        $moved = [];
        foreach ($variants as $name) {
            $to = in_array(self::FILE, scandir($gridDir) ?: [], true) ? "$name~" : self::FILE;
            if (@rename("$gridDir/$name", "$gridDir/$to")) {
                $moved[] = [$name, $to];
            }
        }

        return $moved;
    }

    /**
     * The config of the services of a grid folder, empty when it has none: its Robust config, or for a standalone the master of its
     * simulator (the constants) and its StandaloneCommon.ini (what the viewers are told).
     */
    private static function robust(string $gridDir): string
    {
        foreach (['Robust.HG.ini', 'Robust.ini'] as $file) {
            if (is_file("$gridDir/$file")) {
                return GridFileService::read("$gridDir/$file");
            }
        }
        $text = '';
        foreach (['OpenSimGridDefaults.ini', 'config-include/StandaloneCommon.ini'] as $file) {
            is_file("$gridDir/$file") && ($text .= GridFileService::read("$gridDir/$file") . "\n");
        }

        return $text;
    }

    /** The setting of the Robust config of a grid (`WebURL`, `economy`...), empty when it has not. */
    private static function robustValue(string $gridDir, string $key): string
    {
        return preg_match('/^\s*' . preg_quote($key, '/') . '\s*=\s*"?([^"\r\n]*?)"?\s*$/m', self::robust($gridDir), $m)
            ? trim($m[1])
            : '';
    }

    /** The address of the web site of the grid: the `WebURL` of its Robust config. */
    public static function webUrl(string $gridDir): string
    {
        return self::robustValue($gridDir, 'WebURL');
    }

    /** Where the helpers are: the path of the economy the grid gives to its viewers. */
    public static function basePath(string $gridDir): string
    {
        $economy = self::robustValue($gridDir, 'economy');

        return $economy === '' ? self::DEFAULT_PATH : Services::pathOf($economy);
    }

    /** The services as the grid has them. */
    public static function services(string $gridDir): Services
    {
        return new Services(self::basePath($gridDir));
    }

    /**
     * The file of a grid that has none yet: every setting is a comment, the operator uncomments what he needs.
     */
    public static function template(): string
    {
        return <<<'INI'
        ;; Helpers.ini: what opensim-helpers need and OpenSim.ini and Robust.ini have no place for, in the format of
        ;; OpenSimulator. The settings that have an official place (the name of the grid, its addresses, the message of
        ;; the day, the databases, the economy module and Gloebit...) are read from OpenSim.ini and Robust.ini, do not copy them here. This file may hold
        ;; any of them, in the same sections, for a web host that has no OpenSimulator, but what the other files say wins.
        ;; Like every ini file of the grid, it may hold passwords: mode 640, group of the web server.

        [Helpers]
        ;; The destinations of the guide: a file (looked for in this folder) or a URL, "Name|Region URL" lines, a name
        ;; alone starts a category
        ;; GuideSource = "destinations.txt"
        ;; The registry of the search (parcels, hosts, events...): Robust's database unless it has its own
        ;; SearchConnectionString = "Data Source=localhost;Database=ossearch;User ID=opensim;Password=*****;"
        ;; HYPEvents, the calendar the events are read from
        ;; HypEventsURL = "https://2do.directory/events"
        ;; Whatever this folder holds (templates/, assets/) replaces the files of the helpers
        ;; ThemePath = "theme"
        ;; LogoURL = "https://example.org/logo.png"
        ;; MailSender = "no-reply@example.org"

        INI;
    }
}
