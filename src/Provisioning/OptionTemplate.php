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
 *  - any other path (`config-include/osslEnable.ini`): the same file of the folder of the grid.
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
 * Default = true                     on unless the setup says otherwise
 * Answer = search                    the name of the answer in the setup (the name of the folder)
 * Group = economy                    alternatives: one at a time, the name of the folder is `<group>-<variant>`
 * Fallback = true                    the one of its group that is on when no other is (disabling an option falls back to it)
 * Requires = helpers, !standalone    what the grid must be or have: helpers, standalone, hypergrid, connector:<Name>, service:<name>
 * Uses = helpers                     what a grid made by the setup must have for the option to be of any use (helpers)
 * Modules = opensimsearch            modules of the install it needs (EnabledModules, the package of the core)
 * Asks = Gloebit/GLBKey              settings the administrator gives: told when they are missing
 * Note = "..."                       what is told of them ({keys})
 * Needs = "..."                      what is told when the grid cannot have it ({nick})
 * Initial = Gloebit/GLBEnvironment   written only where the file gives no value: the administrator's after that
 * Existing = Gloebit/Enabled         written only where the file already has the setting
 * Clear = Messaging/OfflineMessageURL  taken off when the grid has no value for it (a {{variable}} with nothing to say)
 * Keep = DataSnapshot/index_sims     left in place when the option is disabled (something else may use them)
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
        return $this->text('Label') ?: $this->name();
    }

    public function description(): string
    {
        return $this->text('Description');
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
        return $this->text('Answer') ?: ($this->group() !== '' ? $this->group() : $this->name());
    }

    /** The group of alternatives it is in, empty when it is alone. */
    public function group(): string
    {
        return $this->text('Group');
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
        return $this->text('Note');
    }

    /** What is told when the grid cannot have the option. */
    public function needs(): string
    {
        return $this->text('Needs');
    }

    /** Whether a setting (`Section/Key`) is listed under a key of the manifest. */
    public function lists(string $manifestKey, string $section, string $key): bool
    {
        return in_array(strtolower("$section/$key"), array_map('strtolower', $this->list($manifestKey)), true);
    }

    /**
     * The ini files of the option, relative to its folder.
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
            if ($file->isFile() && preg_match('/\.ini$/i', $file->getFilename()) === 1) {
                $files[] = substr($file->getPathname(), strlen($this->directory) + 1);
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
    public function settings(string $file, array $variables, bool $enable = true): array
    {
        $path = "{$this->directory}/$file";
        $text = is_file($path) ? TextFileService::read($path) : '';
        $settings = [];
        $section = '';
        $seen = [];
        foreach (explode("\n", TextFileService::unix($text)) as $line) {
            if (preg_match('/^\s*\[(.+?)\]\s*$/', $line, $m)) {
                $section = trim($m[1]);

                continue;
            }
            if ($section === '' || !preg_match('/^\s*([^\s;#=][^=]*?)\s*=\s*(.*?)\s*$/', $line, $m)) {
                continue;
            }
            $key = self::fill($m[1], $variables);
            $value = $enable ? self::fill($m[2], $variables) : $m[2];
            $id = strtolower("$section|$key");
            if ($key === null || $value === null || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $settings[] = [$section, $key, $value];
        }

        return $settings;
    }

    /** The text of a file, as the settings it holds once the grid's own are put in: what it lays over the file of the grid. */
    public function render(string $file, array $variables): string
    {
        $text = '';
        $section = null;
        foreach ($this->settings($file, $variables) as [$name, $key, $value]) {
            if ($name !== $section) {
                $text .= ($section === null ? '' : "\n") . "[$name]\n";
                $section = $name;
            }
            $text .= $value === '' ? "$key =\n" : "$key = $value\n";
        }

        return $text;
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

    private function text(string $key): string
    {
        return (string) ($this->manifest->get('Option', $key) ?? '');
    }

    private function flag(string $key, bool $default): bool
    {
        $value = strtolower($this->text($key));

        return $value === '' ? $default : in_array($value, ['true', 'yes', '1', 'on'], true);
    }

    /** @return list<string> */
    private function list(string $key): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $this->text($key))), 'strlen'));
    }
}
