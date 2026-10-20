<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Config\GridConfig;
use OpenSim\Config\IniEditor;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\Web\Services;
use OpenSim\Provisioning\Web\Snippets;

/**
 * The options of a grid (`opensim enable|disable <grid> <option>`, and the options of the setup): the presets that have an
 * `option.conf` (see OptionTemplate), in the folders of presets of the machine (<etc>/presets, the administrator's) and of the kit
 * (share/presets). Enabling one lays each ini file of the preset over the file of the grid that has the same name, in the files of
 * the simulators (the config each one reads), in the OpenSim.ini of the grid for the ones it already has (the helpers read it first,
 * a setting there that is wrong hides the right one) and, for what Robust says, in its Robust config. Nothing else is touched: the
 * administrator customizes the rest in the files. A setting that is there is changed in place, and disabling comments it out, with
 * its value, so that it can be seen and replaced.
 *
 * The options a grid has are kept in its description (`Options = search, offline-helpers`); a grid that does not say has the ones
 * that are on by default and that it can have.
 */
final class GridOptions
{
    /** @var array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} */
    private const RESULT = ['error' => null, 'simulators' => [], 'grid' => false, 'robust' => false, 'files' => [], 'notes' => []];

    /** @var array<string,OptionTemplate>|null */
    private ?array $templates = null;

    /**
     * @param ?bool $layered whether the OpenSim.ini of the grid holds the settings of its simulators (it has a master, see
     *                       SimulatorMaster); null: it does when the master is there
     * @param array<string,string> $extra more variables for the files: what a setup collected (see GridPlan::variables())
     */
    public function __construct(
        private Grid $grid,
        private ?Presets $presets = null,
        private ?bool $layered = null,
        private array $extra = [],
    ) {}

    /**
     * The options there are.
     *
     * @return array<string,OptionTemplate> by name
     */
    public function templates(): array
    {
        return $this->templates ??= ($this->presets ??= new Presets(
            [],
            $this->grid->environment()->layout->etcRoot(),
        ))->options();
    }

    /** An option by its name. */
    public function template(string $name): ?OptionTemplate
    {
        return $this->templates()[strtolower(trim($name))] ?? null;
    }

    /**
     * The option a word means: its name, else the one of a group (`economy`) that is on, else the one of the variant
     * (`economy` and `gloebit`), else the one the group has by default.
     */
    public function resolve(string $word, string $variant = ''): ?OptionTemplate
    {
        $word = strtolower(trim($word));
        $variant = strtolower(trim($variant));
        if ($variant !== '' && ($found = $this->template("$word-$variant")) !== null) {
            return $found;
        }
        if ($variant === '' && ($found = $this->template($word)) !== null) {
            return $found;
        }
        $members = array_filter($this->templates(), static fn(OptionTemplate $t): bool => $t->group() === $word);
        if ($variant !== '') {
            foreach ($members as $member) {
                if ($member->variant() === $variant) {
                    return $member;
                }
            }

            return null;
        }
        foreach ($members as $member) {
            if (in_array($member->name(), $this->enabled(), true)) {
                return $member;
            }
        }

        return array_values($this->defaults($members))[0] ?? (array_values($members)[0] ?? null);
    }

    /**
     * What stops the grid from having an option, null when it can: the first thing it requires that the grid is not or has not.
     *
     * @param string $prefix the address of the services of the helpers, when they are given for the option
     */
    public function unmet(OptionTemplate $option, string $prefix = ''): ?string
    {
        foreach ($option->requires() as $atom) {
            $negated = str_starts_with($atom, '!');
            $word = strtolower(ltrim($atom, '!'));
            if ($this->fact($word, $prefix) === $negated) {
                return $atom;
            }
        }

        return null;
    }

    /**
     * The options the grid has: the ones it says, else the ones that are on by default and that it can have.
     *
     * @return list<string>
     */
    public function enabled(): array
    {
        $recorded = $this->grid->options();
        if ($recorded !== null) {
            return array_values(array_filter($recorded, fn(string $name): bool => $this->template($name) !== null));
        }
        $names = array_keys($this->defaults($this->templates()));
        // A grid made before the options said how it keeps the offline messages
        $mode = $this->grid->offlineMode();
        $offline = $mode !== '' ? $this->template("offline-$mode") : null;
        if ($offline !== null && $this->unmet($offline) === null) {
            $names = array_values(array_filter($names, fn(string $n): bool => $this->template($n)?->group() !== 'offline'));
            $names[] = $offline->name();
        }

        return $names;
    }

    /**
     * Which options a setup makes, from its answers: `options` says by name, the other answers by the name they have in the options
     * (`ossl: true`, `economy: gloebit`, `search: shared`), the rest is what the options are by default. An option the grid cannot
     * have is not one, and a group has one option.
     *
     * @param array<string,mixed> $answers
     * @return list<string>
     */
    public function selection(array $answers): array
    {
        $options = is_array($answers['options'] ?? null) ? $answers['options'] : [];
        // A list says which options are on, and no other; a map says the ones it names
        $exact = array_is_list($options) && array_key_exists('options', $answers)
            ? array_flip(array_map('strtolower', array_map('strval', $options)))
            : null;
        $named = $exact === null ? $options : [];
        $chosen = [];
        $explicit = [];
        foreach ($this->templates() as $name => $option) {
            $given = array_key_exists($name, $named) ? $named[$name] : null;
            $key = $option->answer();
            if ($given === null && $option->group() !== '' && is_string($answers[$key] ?? null)) {
                $given = strtolower((string) $answers[$key]) === $option->variant();
            } elseif ($given === null && array_key_exists($key, $answers) && $option->group() === '') {
                $given = $answers[$key];
            }
            $on = match (true) {
                $exact !== null => isset($exact[$name]),
                $given !== null => self::truthy($given),
                default => $option->default(),
            };
            if ($on && $this->unmet($option) === null) {
                $chosen[$name] = $option;
                ($given === null && $exact === null) || ($explicit[$name] = true);
            }
        }
        // A group that has none (the one asked for cannot be had) has the one it falls back to
        $groups = array_column(array_map(static fn(OptionTemplate $o): array => [$o->group(), true], $chosen), 1, 0);
        foreach ($this->templates() as $name => $option) {
            if ($exact === null && $option->fallback() && $option->group() !== '' && !isset($groups[$option->group()]) && $this->unmet($option) === null) {
                $chosen[$name] = $option;
            }
        }
        // One option of a group: the one that was asked for, else the first
        $byGroup = [];
        foreach ($chosen as $name => $option) {
            $held = $byGroup[$option->group()] ?? null;
            if ($option->group() !== '' && ($held === null || self::rank($option, $explicit) > self::rank($chosen[$held], $explicit))) {
                $byGroup[$option->group()] = $name;
            }
        }

        $chosen = array_filter(
            $chosen,
            static fn(OptionTemplate $option, string $name): bool => $option->group() === '' || $byGroup[$option->group()] === $name,
            ARRAY_FILTER_USE_BOTH,
        );
        uksort($chosen, static fn(string $a, string $b): int => [$chosen[$a]->order(), $a] <=> [$chosen[$b]->order(), $b]);

        return array_keys($chosen);
    }

    /**
     * What an option weighs against the others of its group: the one that was asked for, then the ones that are on by default, then
     * the one the group falls back to.
     *
     * @param array<string,bool> $explicit the options that were asked for, by name
     */
    private static function rank(OptionTemplate $option, array $explicit): int
    {
        return isset($explicit[$option->name()]) ? 2 : ($option->fallback() ? 0 : 1);
    }

    /**
     * The OpenSim.ini of the options the grid has, as one text: what a simulator of a grid that has no master says for them.
     */
    public function layer(): string
    {
        $ini = new IniEditor('');
        $variables = $this->variables();
        foreach ($this->enabled() as $name) {
            $option = $this->template($name);
            if ($option === null || !in_array('OpenSim.ini', $option->files(), true)) {
                continue;
            }
            foreach ($option->settings('OpenSim.ini', $variables, true, fn(string $word): bool => $this->fact($word, '')) as [$section, $key, $value]) {
                if ($value !== '' && !$option->lists('Existing', $section, $key)) {
                    $ini->set($section, $key, $value);
                }
            }
        }

        return trim($ini->toString()) . "\n";
    }

    /**
     * Enable an option of the grid.
     *
     * @param  array{prefix?:string} $params the address of the services of the helpers (`<prefix>/query.php`) for an option that
     *                                      asks them from another place than the grid's
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>}
     */
    public function enable(string $name, array $params = []): array
    {
        $result = self::RESULT;
        $option = $this->template($name);
        $prefix = rtrim(trim($params['prefix'] ?? ''), '/');
        if ($option === null) {
            $result['error'] = "unknown option '$name' (" . implode(', ', array_keys($this->templates())) . ')';

            return $result;
        }
        if ($prefix !== '' && Grid::validateWebUrl($prefix) !== null) {
            $result['error'] = "'$prefix' is not an address, give the start of the address of the services (https://www.example.org/helpers)";

            return $result;
        }
        if (($unmet = $this->unmet($option, $prefix)) !== null) {
            $result['error'] = $option->needs() !== ''
                ? str_replace('{nick}', $this->grid->nick(), $option->needs())
                : "the grid {$this->grid->nick()} cannot have {$option->label()}: " . ($unmet[0] === '!' ? 'it must not be ' . substr($unmet, 1) : "it needs $unmet");

            return $result;
        }
        // One at a time in a group
        $others = [];
        if ($option->group() !== '') {
            foreach ($this->enabled() as $other) {
                if ($other !== $option->name() && $this->template($other)?->group() === $option->group()) {
                    $others[] = $other;
                    $this->lay($this->template($other), false, $this->variables(), $result);
                }
            }
        }
        $this->lay($option, true, $this->variables($prefix, $option), $result);
        $this->finish($result);
        array_push($result['notes'], ...$this->notes($option));
        $this->record(array_values(array_unique([...array_diff($this->enabled(), $others), $option->name()])));

        return $result;
    }

    /**
     * Disable an option of the grid.
     *
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>}
     */
    public function disable(string $name): array
    {
        $result = self::RESULT;
        $option = $this->template($name);
        if ($option === null) {
            $result['error'] = "unknown option '$name' (" . implode(', ', array_keys($this->templates())) . ')';

            return $result;
        }
        $this->lay($option, false, $this->variables(), $result);
        $this->finish($result);
        $this->record(array_values(array_diff($this->enabled(), [$option->name()])));

        return $result;
    }

    /**
     * The OpenSim.ini that holds what the grid decides for its simulators: the one of its folder, or the one of its preset for a grid
     * joined by a preset (what applies to all its simulators is set there).
     */
    public function general(): string
    {
        return $this->root() . '/OpenSim.ini';
    }

    /**
     * The simulators of the grid made on this machine: the name of the instance and its ini file.
     *
     * @return array<string,string> instance => path
     */
    public function simulators(): array
    {
        $simulators = [];
        $files = glob(GridConfig::simulatorsDirectory($this->grid->directory()) . '/*.ini') ?: [];
        sort($files);
        foreach ($files as $file) {
            $simulators[basename($file, '.ini')] = $file;
        }

        return $simulators;
    }

    /** The folder that holds the files of the grid: its own, or the one of its preset when it joined a grid by one. */
    private function root(): string
    {
        return $this->grid->preset() !== ''
            ? SimulatorMaster::presetDir($this->grid->directory()) ?? $this->grid->directory()
            : $this->grid->directory();
    }

    /**
     * Whether the grid is, or has, something an option requires.
     *
     * @param string $word standalone, hypergrid, helpers, connector:<Name>, service:<name of a service of the helpers>
     */
    private function fact(string $word, string $prefix): bool
    {
        return match (true) {
            $word === 'standalone' => $this->grid->isStandalone(),
            $word === 'hypergrid' => $this->grid->isHypergrid(),
            $word === 'helpers' => $prefix !== '' || $this->grid->hasHelpers(),
            $word === 'master' => $this->layered(),
            str_starts_with($word, 'service:') => $prefix !== '' || $this->grid->helperUrl(substr($word, 8)) !== '',
            str_starts_with($word, 'connector:') => in_array(
                strtolower(substr($word, 10)),
                array_map('strtolower', $this->grid->connectors()),
                true,
            ),
            // A word that is the name of a variable holds when it has something to say
            default => !in_array($this->variables($prefix)[$word] ?? '', ['', 'false', '0'], true),
        };
    }

    /**
     * The options that are on by default and that the grid can have, one for each group (the first).
     *
     * @param  array<string,OptionTemplate> $from
     * @return array<string,OptionTemplate>
     */
    private function defaults(array $from): array
    {
        $taken = [];
        $defaults = [];
        // The one a group falls back to comes after the others
        uasort($from, static fn(OptionTemplate $a, OptionTemplate $b): int => $a->fallback() <=> $b->fallback());
        foreach ($from as $name => $option) {
            if (!$option->default() || $this->unmet($option) !== null || isset($taken[$option->group()])) {
                continue;
            }
            $defaults[$name] = $option;
            $option->group() === '' || ($taken[$option->group()] = true);
        }
        uksort($defaults, static fn(string $a, string $b): int => [$from[$a]->order(), $a] <=> [$from[$b]->order(), $b]);

        return $defaults;
    }

    /**
     * What an option tells of the grid, to put in its files: its name, its data service of the search, the addresses of the helpers.
     *
     * @return array<string,string>
     */
    private function variables(string $prefix = '', ?OptionTemplate $also = null): array
    {
        $url = fn(string $service): string => $prefix !== '' && isset(Services::SCRIPTS[$service])
            ? "$prefix/" . Services::SCRIPTS[$service]
            : $this->grid->helperUrl($service);
        $economy = $this->servicesFile();
        $current = $economy !== null && is_file($economy) ? IniEditor::load($economy)->get('GridInfoService', 'economy') : null;
        $currency = $this->grid->helperUrl('currency');
        $variables = $this->extra + [
            'grid_name' => $this->grid->name(),
            'grid_nick' => $this->grid->nick(),
            'data_service' => SimulatorConfig::dataServiceNameOf($this->grid->nick(), $this->grid->name()),
            // The address of the economy the grid tells the viewers: the one given, the one it tells, else where its helpers are
            'economy_url' => $prefix !== '' ? $prefix : (($current ?? '') !== '' ? (string) $current : ($currency === '' ? '' : dirname($currency))),
            // Robust answers the simulators of a grid; a standalone keeps what Robust keeps in its own process
            'robust_url' => $this->grid->isStandalone() ? '' : '${Const|PrivURL}:${Const|PrivatePort}',
        ];
        // The services and the pages of the helpers: their address, their path on the site of the grid (none when its search is elsewhere)
        foreach ([...array_keys(Services::SCRIPTS), ...array_keys(Services::PAGES)] as $service) {
            $variables["url_$service"] = $url($service);
            $variables["page_$service"] = $prefix !== '' && $service === 'search' ? '' : $this->pagePath($service);
        }
        // Where the helpers are on the site: the path of their economy
        $base = dirname($variables['page_currency']);
        $variables['helpers_base'] = $variables['page_currency'] === '' ? '' : ($base === '.' ? '/' : $base);
        // The site of the grid, and what the options that are on give to the others
        $variables += Snippets::siteVariables($this->grid->webUrl());
        foreach ([...$this->enabled(), ...($also === null ? [] : [$also->name()])] as $name) {
            $variables += $this->template($name)?->variables() ?? [];
        }

        return $variables;
    }

    /** The path of a page of the helpers on the site of the grid, empty when the grid has no helpers. */
    private function pagePath(string $page): string
    {
        return (string) (parse_url($this->grid->helperUrl($page), PHP_URL_PATH) ?? '');
    }

    /**
     * Lay an option over the files of the grid, or take it off them.
     *
     * @param array<string,string> $variables
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     */
    private function lay(OptionTemplate $option, bool $on, array $variables, array &$result): void
    {
        $fact = fn(string $word): bool => $this->fact($word, '');
        foreach ($option->files() as $file) {
            $simulators = $file === 'OpenSim.ini';
            $services = preg_match('/^Robust(\.HG)?\.ini$/i', $file) === 1;
            $path = null;
            if (!$simulators && !$services) {
                // A file of the grid: the preset's own when the grid has none, an ini the grid has is merged, any other is the grid's
                $name = $option->path($file, $variables);
                if ($name === null) {
                    continue;
                }
                $path = $this->root() . "/$name";
                if (!is_file($path)) {
                    $on && $this->copy($option, $file, $path, $variables, $fact, $result);

                    continue;
                }
                if (preg_match('/\.ini$/i', $file) !== 1) {
                    continue;
                }
            }
            $settings = $option->settings($file, $variables, $on, $fact);
            // What the grid has no value for, and the option says to take off when it has none
            $cleared = $on
                ? array_values(array_filter(
                    array_udiff(
                        $option->settings($file, $variables, false, $fact),
                        $settings,
                        static fn(array $a, array $b): int => strcasecmp("$a[0]/$a[1]", "$b[0]/$b[1]"),
                    ),
                    static fn(array $setting): bool => $option->lists('Clear', $setting[0], $setting[1]),
                ))
                : [];
            if ($settings === [] && $cleared === []) {
                continue;
            }
            $change = function (IniEditor $ini, bool $general) use ($option, $settings, $cleared, $on, $file): void {
                $only = $file === 'OpenSim.ini' && ($this->layered() ? !$general : $general);
                foreach ($settings as [$section, $key, $value]) {
                    $on
                        ? self::put($ini, $option, $section, $key, $value, $only)
                        : self::take($ini, $option, $section, $key);
                }
                foreach ($cleared as [$section, $key]) {
                    self::take($ini, $option, $section, $key);
                }
            };
            if ($simulators) {
                $this->eachSimulatorFile($result, $change);
            } elseif ($services) {
                $result['robust'] = $this->robust(fn(IniEditor $ini) => $change($ini, false), $result) || $result['robust'];
            } else {
                $this->edit($path, fn(IniEditor $ini) => $change($ini, false)) && ($result['files'][] = $path);
            }
        }
    }

    /**
     * A file of an option that the grid does not have: the preset's, with the variables put in. A file that is not an ini is public.
     *
     * @param array<string,string> $variables
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     */
    private function copy(OptionTemplate $option, string $file, string $path, array $variables, callable $fact, array &$result): void
    {
        is_dir(dirname($path)) || @mkdir(dirname($path), 0o755, true);
        file_put_contents($path, $option->text($file, $variables, $fact));
        preg_match('/\.ini$/i', $path) === 1 || @chmod($path, 0o644);
        $result['files'][] = $path;
    }

    /**
     * Lay the settings of a file of a preset over an ini, with the rules the preset says for them.
     *
     * @param array<string,string> $variables
     * @param ?callable(string):bool $fact whether a word of a block holds; none: every block applies
     */
    public static function layText(IniEditor $ini, OptionTemplate $option, string $file, array $variables, ?callable $fact = null): void
    {
        foreach ($option->settings($file, $variables, true, $fact) as [$section, $key, $value]) {
            self::put($ini, $option, $section, $key, $value, false);
        }
    }

    /** Set a setting of an option in a file, with the rules the option says for it. */
    private static function put(IniEditor $ini, OptionTemplate $option, string $section, string $key, string $value, bool $only): void
    {
        if ($option->lists('Existing', $section, $key) && !$ini->mentions($section, $key)) {
            return;
        }
        if ($option->lists('Initial', $section, $key) && ($ini->get($section, $key) ?? '') !== '') {
            return;
        }
        // The file does not have what the grid decides, or has it as a copy of what the simulators say
        if ($only && !$ini->mentions($section, $key)) {
            return;
        }
        match ($value) {
            '' => $ini->uncomment($key),
            '{{off}}' => $ini->comment($section, $key),
            default => $ini->set($section, $key, $value),
        };
    }

    /** Take a setting of an option off a file: commented out with its value. */
    private static function take(IniEditor $ini, OptionTemplate $option, string $section, string $key): void
    {
        if ($option->lists('Existing', $section, $key) || $option->lists('Keep', $section, $key)) {
            return;
        }
        $ini->comment($section, $key);
    }

    /**
     * The master of the simulators follows the OpenSim.ini of the grid, where a grid that has one keeps what it decides.
     *
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     */
    private function finish(array $result): void
    {
        if ($result['error'] === null && $this->layered === null && $this->layered() && $this->grid->coreDirectory() !== '') {
            (new SimulatorMaster())->ensure($this->grid->directory(), $this->grid->coreDirectory() . '/bin');
        }
    }

    /** Whether the grid has a master for its simulators: what it decides is in its OpenSim.ini, its simulators say what is their own. */
    private function layered(): bool
    {
        return $this->layered ?? SimulatorMaster::exists($this->grid->directory());
    }

    /**
     * Edit the ini file of each simulator of the grid and the OpenSim.ini of the grid, and keep which ones changed.
     *
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     * @param callable(IniEditor,bool):void $edit given the file and whether it is the OpenSim.ini of the grid
     */
    private function eachSimulatorFile(array &$result, callable $edit): void
    {
        $general = $this->general();
        if (is_file($general) && $this->edit($general, fn(IniEditor $ini) => $edit($ini, true))) {
            $result['grid'] = true;
        }
        foreach ($this->simulators() as $instance => $file) {
            if ($this->edit($file, fn(IniEditor $ini) => $edit($ini, false))) {
                $result['simulators'][] = $instance;
            }
        }
    }

    /**
     * Edit the Robust config of the grid, when it is on this machine.
     *
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,files:list<string>,notes:list<string>} $result
     */
    private function robust(callable $edit, array &$result): bool
    {
        $ini = $this->servicesFile();
        if ($this->grid->isRemote() || $ini === null || !is_file($ini)) {
            $note = "Robust of {$this->grid->nick()} is not on this machine: the same change is to make in its config there";
            in_array($note, $result['notes'], true) || ($result['notes'][] = $note);

            return false;
        }

        return $this->edit($ini, $edit);
    }

    /** The config of the services of the grid: its Robust, or the StandaloneCommon.ini of the folder of a standalone, which has none. */
    private function servicesFile(): ?string
    {
        return $this->grid->isStandalone()
            ? $this->grid->directory() . '/config-include/StandaloneCommon.ini'
            : $this->grid->robustIni();
    }

    /** Run an edit on an ini file and save it when it changed. */
    private function edit(string $path, callable $edit): bool
    {
        $ini = IniEditor::load($path);
        $before = $ini->toString();
        $edit($ini);
        if ($ini->toString() === $before) {
            return false;
        }
        $ini->save($path);

        return true;
    }

    /**
     * What the administrator must know once an option is on: its modules that are not enabled for the install or not installed, and
     * the settings it asks for that nobody gave.
     *
     * @return list<string>
     */
    private function notes(OptionTemplate $option): array
    {
        $notes = [];
        $layout = $this->grid->environment()->layout;
        $core = basename($layout->coreDirectory());
        foreach ($option->modules() as $module) {
            if (!in_array($module, $layout->enabledModules(), true)) {
                $notes[] = "the module $module is not in EnabledModules of opensim.conf, the simulators do not load it";
            }
            if ($core !== '' && !is_dir($layout->modulesRoot() . "/$core/$module")) {
                $notes[] = "the module $module is not installed for $core (apt install opensim-$core-$module)";
            }
        }
        foreach ($option->paths() as $path) {
            file_exists($path) ||
                ($notes[] = $option->pathNote() !== '' ? $option->pathNote() : "$path is not there, {$option->label()} needs it");
        }
        $missing = $this->missing($option);
        if ($missing !== []) {
            $notes[] = str_replace(
                '{keys}',
                implode(', ', $missing),
                $option->note() !== '' ? $option->note() : "{$option->label()}: give {keys} in the OpenSim.ini of the grid or of the simulators",
            );
        }

        return $notes;
    }

    /**
     * The settings an option asks for that neither a simulator nor its grid has: what a simulator says, else what its grid says when it
     * has a master.
     *
     * @return list<string> the keys
     */
    private function missing(OptionTemplate $option): array
    {
        $missing = [];
        $general = $this->layered() ? IniEditor::load($this->general()) : null;
        foreach ([null, ...array_values($this->simulators())] as $file) {
            $ini = $file === null ? $general : IniEditor::load($file);
            if ($ini === null) {
                continue;
            }
            foreach ($option->asks() as $ask) {
                [$section, $key] = explode('/', $ask, 2) + [1 => ''];
                if ($ini->get($section, $key) === null && ($file === null || $general?->get($section, $key) === null)) {
                    $missing[$key] = true;
                }
            }
        }

        return array_keys($missing);
    }

    /**
     * Keep which options the grid has, in its description.
     *
     * @param list<string> $names
     */
    private function record(array $names): void
    {
        $path = $this->grid->directory() . '/' . basename($this->grid->directory()) . '.conf';
        if (!is_file($path)) {
            return;
        }
        $ini = IniEditor::load($path);
        $ini->set('Grid', 'Options', implode(', ', $names));
        $ini->save($path);
        // The grid is read again: what it says is what was just written
        $this->grid->isPlanned() || ($this->grid = Grid::at($this->grid->environment(), $this->grid->directory()));
    }

    private static function truthy(mixed $value): bool
    {
        return is_string($value)
            ? !in_array(strtolower(trim($value)), ['', '0', 'false', 'no', 'off', 'none', 'disabled'], true)
            : (bool) $value;
    }
}
