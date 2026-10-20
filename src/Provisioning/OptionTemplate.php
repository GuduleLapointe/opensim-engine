<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Config\IniEditor;
use OpenSim\Config\TextFileService;

/**
 * An option of a grid, as a folder of presets: the ini files it changes, with the structure of the folder of a grid, and `option.conf`,
 * which says what the option is and what it needs. Each ini file is laid over the file of the grid that has the same name:
 *
 *  - `OpenSim.ini`: the config of the simulators (the OpenSim.ini of the grid and the files of its simulators);
 *  - `Robust.ini` (or `Robust.HG.ini`): the services of the grid, its Robust config or, for a standalone, its StandaloneCommon.ini;
 *  - any other path (`config-include/osslEnable.ini`, `Helpers.ini`, `web/{{grid_nick}}.caddyfile`): the same file of the folder of
 *    the grid. A file the grid does not have is the preset's, copied with its variables put in (a name, a comment, a file that is
 *    not an ini included); a file the grid has is kept as it is, unless it is an ini: its settings are merged.
 *
 * In these files a setting is a setting of OpenSimulator, as it is written there. `{{name}}` is replaced by what the grid knows of it
 * (`grid_name`, `grid_nick`, `data_service`, `url_<service>` and `page_<page>` of the helpers, `helpers_base`... see
 * GridOptions::variables()); a setting that needs something the grid does not have is not written. A setting without a value
 * (`OfflineIMServiceConnector =`) is the one of the file, switched on as it is. Disabling an option comments out the settings it set.
 *
 * ```
 * [Option]
 * Label = "Cross-grid search"        what it is called (the setup lists it)
 * Description = "..."
 * Offer = true                       listed in the setup (false: applied by itself when the grid can have it)
 * Show = false                       not told in the report of the setup (the plumbing of the grid)
 * Default = true                     on unless the setup says otherwise
 * Answer = search                    the name of the answer in the setup (the name of the folder)
 * Group = economy                    alternatives: one at a time, the name of the folder is `<group>-<variant>`
 * Fallback = true                    the one of its group that is on when no other is
 * Order = 10                         when it is laid over the files, the lowest first (default 50)
 * Requires = helpers, !standalone    what the grid must be or have: helpers, standalone, hypergrid, connector:<Name>, service:<name>
 * Uses = helpers                     what a grid made by the setup must have for the option to be of any use (helpers)
 * Modules = opensimsearch            modules of the install it needs (EnabledModules, the package of the core)
 * Paths = /usr/share/opensim-web/html  folders or files of the install it needs
 * PathNote = "..."                   what is told when one is not there
 * Asks = Gloebit/GLBKey              settings the administrator gives: told when they are missing
 * Note = "..."                       what is told of them ({keys})
 * Needs = "..."                      what is told when the grid cannot have it ({nick})
 * Initial = Gloebit/GLBEnvironment   written only where the file gives no value: the administrator's after that
 * Existing = Gloebit/Enabled         written only where the file already has the setting
 * Clear = Messaging/OfflineMessageURL  taken off when the grid has no value for it (a {{variable}} with nothing to say)
 * Keep = DataSnapshot/index_sims     left in place when the option is disabled (something else may use them)
 *
 * In a file, `;; @if standalone, !hypergrid` ... `;; @end` keeps the lines between for a grid that is one and is not the other (the
 * words of Requires). A setting with the value `{{off}}` is commented out.
 *
 * [Variables]                        values the other presets can put in their files: `docroot = /var/www/html` is `{{docroot}}`
 * ```
 */
final class OptionTemplate
{
    public const MANIFEST = 'option.conf';

    private IniEditor $manifest;

    public function __construct(private string $directory)
    {
        $this->manifest = IniEditor::load("$directory/" . self::MANIFEST);
    }

    /** Whether a folder of presets is an option. */
    public static function is(string $directory): bool
    {
        return is_file("$directory/" . self::MANIFEST);
    }

    public function name(): string
    {
        return basename($this->directory);
    }

    public function directory(): string
    {
        return $this->directory;
    }

    public function label(): string
    {
        return $this->value('Label') ?: $this->name();
    }

    public function description(): string
    {
        return $this->value('Description');
    }

    /** Whether the report of the setup tells it is on: the plumbing of the grid is not told. */
    public function shown(): bool
    {
        return $this->flag('Show', true);
    }

    /** Whether the setup lists it. */
    public function offered(): bool
    {
        return $this->flag('Offer', true);
    }

    /** Whether it is on unless the setup says otherwise. */
    public function default(): bool
    {
        return $this->flag('Default', false);
    }

    /** The name of the answer that tells whether it is on (setup files, forms): `search`, `ossl`, `economy` for a group. */
    public function answer(): string
    {
        return $this->value('Answer') ?: ($this->group() !== '' ? $this->group() : $this->name());
    }

    /** The group of alternatives it is in, empty when it is alone. */
    public function group(): string
    {
        return $this->value('Group');
    }

    /** Whether it is the one of its group that is on when no other is. */
    public function fallback(): bool
    {
        return $this->flag('Fallback', false);
    }

    /** What it is in its group (`gloebit` for economy-gloebit). */
    public function variant(): string
    {
        $group = $this->group();

        return $group !== '' && str_starts_with($this->name(), "$group-")
            ? substr($this->name(), strlen($group) + 1)
            : $this->name();
    }

    /** When it is laid over the files, the lowest first (the base of the grid comes before the options). */
    public function order(): int
    {
        return (int) ($this->value('Order') !== '' ? $this->value('Order') : 50);
    }

    /** @return list<string> what the grid must be or have, `!` before a word for the opposite */
    public function requires(): array
    {
        return $this->list('Requires');
    }

    /** @return list<string> what a grid made by the setup must have for the option to be of any use */
    public function uses(): array
    {
        return array_map('strtolower', $this->list('Uses'));
    }

    /** @return list<string> */
    public function modules(): array
    {
        return array_map('strtolower', $this->list('Modules'));
    }

    /** @return list<string> the settings the administrator gives, `Section/Key` */
    public function asks(): array
    {
        return $this->list('Asks');
    }

    /** What is told of the settings that are missing; `{keys}` is their names. */
    public function note(): string
    {
        return $this->value('Note');
    }

    /** @return list<string> the folders or files of the install it needs */
    public function paths(): array
    {
        return $this->list('Paths');
    }

    /** What is told when one of its paths is not there. */
    public function pathNote(): string
    {
        return $this->value('PathNote');
    }

    /**
     * The values it gives to the other presets, by the name they have in a file (`docroot`).
     *
     * @return array<string,string>
     */
    public function variables(): array
    {
        $values = [];
        foreach ($this->manifest->keys('Variables', '/./') as $key) {
            $values[strtolower($key)] = (string) $this->manifest->get('Variables', $key);
        }

        return $values;
    }

    /** What is told when the grid cannot have the option. */
    public function needs(): string
    {
        return $this->value('Needs');
    }

    /** Whether a setting (`Section/Key`) is listed under a key of the manifest. */
    public function lists(string $manifestKey, string $section, string $key): bool
    {
        return in_array(strtolower("$section/$key"), array_map('strtolower', $this->list($manifestKey)), true);
    }

    /**
     * The files of the option, relative to its folder.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $files = [];
        if (!is_dir($this->directory)) {
            return $files;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($this->directory) + 1);
            if ($file->isFile() && $relative !== self::MANIFEST) {
                $files[] = $relative;
            }
        }
        sort($files);

        return $files;
    }

    /**
     * The settings of one of its files, with what the grid knows put in.
     *
     * @param  array<string,string> $variables
     * @param  bool $enable false: only the key has to be known (to comment the setting out)
     * @return list<array{0:string,1:string,2:string}> section, key, value (as written: quotes kept; empty to switch on as it is)
     */
    public function settings(string $file, array $variables, bool $enable = true, ?callable $fact = null): array
    {
        $settings = [];
        $section = '';
        $seen = [];
        foreach ($this->lines($file, $fact) as $line) {
            if (preg_match('/^\s*\[(.+?)\]\s*$/', $line, $m)) {
                $section = trim($m[1]);

                continue;
            }
            if ($section === '' || !preg_match('/^\s*([^\s;#=][^=]*?)\s*=\s*(.*?)\s*$/', $line, $m)) {
                continue;
            }
            $key = self::fill($m[1], $variables);
            // `{{off}}`: the setting is commented out
            $value = !$enable || $m[2] === '{{off}}' ? $m[2] : self::fill($m[2], $variables);
            $id = strtolower("$section|$key");
            if ($key === null || $value === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $settings[] = [$section, $key, $value];
        }

        return $settings;
    }

    /**
     * Where a file goes in the folder of the grid: its name with the variables put in; null when one has nothing to say.
     *
     * @param array<string,string> $variables
     */
    public function path(string $file, array $variables): ?string
    {
        return self::fill($file, $variables);
    }

    /**
     * A file whole, with the variables put in; a line that needs one that has nothing to say is left out.
     *
     * @param array<string,string> $variables
     */
    public function text(string $file, array $variables, ?callable $fact = null): string
    {
        $lines = [];
        foreach ($this->lines($file, $fact) as $line) {
            $filled = self::fill($line, $variables);
            $filled === null || ($lines[] = $filled);
        }

        return implode("\n", $lines);
    }

    /**
     * The lines of a file that apply: a block between `;; @if <words>` and `;; @end` is left out, with its two lines, when one of the
     * words does not hold (`standalone`, `!hypergrid`, see GridOptions::fact()).
     *
     * @param ?callable(string):bool $fact whether a word holds; none: every block applies
     * @return list<string>
     */
    private function lines(string $file, ?callable $fact): array
    {
        $path = "{$this->directory}/$file";
        $lines = [];
        $blocks = [];
        foreach (explode("\n", is_file($path) ? TextFileService::unix(TextFileService::read($path)) : '') as $line) {
            if (preg_match('/^\s*;;\s*@if\s+(.+?)\s*$/', $line, $m) === 1) {
                $blocks[] = $fact === null || self::holds($m[1], $fact);

                continue;
            }
            if (preg_match('/^\s*;;\s*@end\s*$/', $line) === 1) {
                array_pop($blocks);

                continue;
            }
            in_array(false, $blocks, true) || ($lines[] = $line);
        }

        return $lines;
    }

    /**
     * Whether every word of a list (`standalone, !hypergrid`) holds.
     *
     * @param callable(string):bool $fact
     */
    public static function holds(string $words, callable $fact): bool
    {
        foreach (array_filter(array_map('trim', explode(',', $words)), 'strlen') as $word) {
            if ($fact(strtolower(ltrim($word, '!'))) === str_starts_with($word, '!')) {
                return false;
            }
        }

        return true;
    }

    /**
     * A text with the variables put in; null when one of them has nothing to say.
     *
     * @param array<string,string> $variables
     */
    private static function fill(string $text, array $variables): ?string
    {
        $empty = false;
        $filled = (string) preg_replace_callback(
            '/\{\{(\w+)\}\}/',
            static function (array $m) use ($variables, &$empty): string {
                $value = $variables[$m[1]] ?? '';
                $empty = $empty || $value === '';

                return $value;
            },
            $text,
        );

        return $empty ? null : $filled;
    }

    private function value(string $key): string
    {
        return (string) ($this->manifest->get('Option', $key) ?? '');
    }

    private function flag(string $key, bool $default): bool
    {
        $value = strtolower($this->value($key));

        return $value === '' ? $default : in_array($value, ['true', 'yes', '1', 'on'], true);
    }

    /** @return list<string> */
    private function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->value($key))), 'strlen'));
    }
}
