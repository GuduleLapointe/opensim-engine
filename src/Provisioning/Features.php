<?php

namespace OpenSim\Provisioning;

use OpenSim\Config\IniEditor;
use OpenSim\Models\Grid;
use OpenSim\Config\GridConfig;

/**
 * What `opensim enable|disable <grid> <feature>` writes: the settings a feature of a grid needs, in the files of its
 * simulators (the config each one reads), in the OpenSim.ini of the grid for the ones it already has (the helpers
 * read it first, a setting there that is wrong hides the right one) and, for what Robust says, in its Robust config. Nothing
 * else is touched: the operator customizes the rest in the files. A setting that is there is changed in place, and `disable`
 * comments it out, with its value, so that it can be seen and replaced.
 *
 *  - search: the module of search of the simulators (`[Search]`) and their registration to the search service of the helpers
 *    (`[DataSnapshot]`), which the helpers read back (`query.php` answers the module, `register.php` the snapshots);
 *  - economy: the economy module of the simulators (the beta module, or Gloebit, whose keys are the operator's: no default
 *    secret), and for Gloebit the address of the economy that Robust tells the viewers (`currency.php`, `landtool.php`);
 *  - profiles: the profile service the simulators ask for the profiles and their classifieds (`[UserProfiles]`), served by
 *    Robust (`[UserProfilesService]`);
 *  - offline: the messages for the avatars who are not there, kept by the helpers (the module V1 of the simulators: mailed on
 *    request, no limit) or by Robust (the module V2: nothing more to run, no mail, 25 messages per avatar).
 */
final class Features
{
    public const SEARCH = 'search';
    public const ECONOMY = 'economy';
    public const PROFILES = 'profiles';
    public const OFFLINE = 'offline';
    public const NAMES = [self::SEARCH, self::ECONOMY, self::PROFILES, self::OFFLINE];

    /** The modules of offline messages the command knows, by the word it takes, and what OpenSim calls them in `OfflineMessageModule`. */
    public const OFFLINE_MODULES = ['helpers' => 'OfflineMessageModule', 'robust' => '"Offline Message Module V2"'];

    /** The money modules the command knows, by the word it takes, and what OpenSim calls them in `economymodule`. */
    public const MONEY = ['beta' => 'BetaGridLikeMoneyModule', 'gloebit' => 'Gloebit'];

    public function __construct(private Grid $grid) {}

    public static function isFeature(string $word): bool
    {
        return in_array(strtolower($word), self::NAMES, true);
    }

    /**
     * Enable a feature of the grid.
     *
     * @param  array{prefix?:string,money?:string,mode?:string} $options the address of the services (a prefix: `<prefix>/query.php`), the
     *                                                      money module (beta or gloebit), the module of offline messages (helpers or robust)
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>}
     */
    public function enable(string $feature, array $options = []): array
    {
        return $this->apply($feature, true, $options);
    }

    /**
     * Disable a feature of the grid.
     *
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>}
     */
    public function disable(string $feature): array
    {
        return $this->apply($feature, false, []);
    }

    /**
     * The OpenSim.ini that holds what the grid decides for its simulators: the one of its folder, or the one of its preset for a grid
     * joined by a preset (what applies to all its simulators is set there).
     */
    public function general(): string
    {
        $dir = $this->grid->preset() !== '' ? SimulatorMaster::presetDir($this->grid->directory()) : null;

        return ($dir ?? $this->grid->directory()) . '/OpenSim.ini';
    }

    /**
     * The simulators of the grid made on this machine: the name of the instance (grid_simulator) and its ini file.
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

    /**
     * @param  array{prefix?:string,money?:string} $options
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>}
     */
    private function apply(string $feature, bool $on, array $options): array
    {
        $result = ['error' => null, 'simulators' => [], 'grid' => false, 'robust' => false, 'notes' => []];
        $prefix = rtrim(trim($options['prefix'] ?? ''), '/');
        if ($prefix !== '' && !preg_match('#^https?://[^\s/]+#i', $prefix)) {
            $result[
                'error'
            ] = "'$prefix' is not an address, give the start of the address of the services (https://www.example.org/helpers)";

            return $result;
        }

        switch (strtolower($feature)) {
            case self::SEARCH:
                return $this->finish($this->search($on, $prefix, $result));
            case self::ECONOMY:
                $money = strtolower($options['money'] ?? 'beta');
                if ($on && !isset(self::MONEY[$money])) {
                    $result['error'] = "unknown money module '$money' (" . implode(', ', array_keys(self::MONEY)) . ')';

                    return $result;
                }

                return $this->finish($this->economy($on, $money, $prefix, $result));
            case self::PROFILES:
                return $this->finish($this->profiles($on, $result));
            case self::OFFLINE:
                $mode = strtolower($options['mode'] ?? 'helpers');
                if (!isset(self::OFFLINE_MODULES[$mode])) {
                    $result['error'] =
                        "unknown module of offline messages '$mode' (" .
                        implode(', ', array_keys(self::OFFLINE_MODULES)) .
                        ')';

                    return $result;
                }

                return $this->finish($this->offline($on, $mode, $prefix, $result));
        }
        $result['error'] = "unknown feature '$feature' (" . implode(', ', self::NAMES) . ')';

        return $result;
    }

    /**
     * @param  array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>} $result
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>}
     */
    private function search(bool $on, string $prefix, array $result): array
    {
        $name = SimulatorConfig::dataServiceNameOf($this->grid->nick(), $this->grid->name());
        $query = $prefix !== '' ? "$prefix/query.php" : $this->grid->helperUrl('query');
        $register = $prefix !== '' ? "$prefix/register.php" : $this->grid->helperUrl('register');
        if ($on && ($query === '' || $register === '')) {
            $result['error'] =
                "the grid {$this->grid->nick()} has no helpers: give the address of its search service, " .
                "opensim enable {$this->grid->nick()} search https://www.example.org/helpers";

            return $result;
        }

        $this->eachFile($result, function (IniEditor $ini, bool $grid) use ($on, $name, $query, $register): void {
            $service = "DATA_SRV_$name";
            if ($on) {
                $this->put($ini, $grid, 'Search', 'Module', 'OpenSimSearch');
                $this->put($ini, $grid, 'Search', 'SearchURL', $this->quote($query));
                $this->put($ini, $grid, 'DataSnapshot', 'index_sims', 'true');
                $this->put($ini, $grid, 'DataSnapshot', 'gridname', $this->quote($this->grid->name()));
                $this->put($ini, $grid, 'DataSnapshot', $service, $this->quote($register));

                return;
            }
            $ini->comment('Search', 'Module');
            $ini->comment('Search', 'SearchURL');
            $ini->comment('DataSnapshot', $service);
            // A simulator registered to another directory (2do...) keeps telling its snapshot to it
            if ($ini->keys('DataSnapshot', '/^DATA_SRV_/i') === []) {
                $ini->comment('DataSnapshot', 'index_sims');
            }
        });
        // The web search of the grid: the page of the helpers, which the viewers open from the setting of the grid info and of the
        // login service. Given to both when it has neither; a grid that sent its search elsewhere (a prefix) keeps its settings
        if ($prefix === '') {
            $result['robust'] =
                $this->robust(function (IniEditor $ini) use ($on): void {
                    $path = $this->pagePath('search');
                    $search = $ini->get('GridInfoService', 'search') ?? '';
                    $url = $ini->get('LoginService', 'SearchURL') ?? '';
                    if (!$on) {
                        $ini->comment('GridInfoService', 'search');
                        $ini->comment('LoginService', 'SearchURL');

                        return;
                    }
                    if ($path !== '' && $search === '' && $url === '') {
                        $search = '${Const|WebURL}' . $path;
                        $ini->set('GridInfoService', 'search', $this->quote($search));
                        $ini->set('LoginService', 'SearchURL', $this->quote($search));
                    } elseif ($search !== '' && $url === '') {
                        $ini->set('LoginService', 'SearchURL', $this->quote($search));
                    } elseif ($url !== '' && $search === '') {
                        $ini->set('GridInfoService', 'search', $this->quote($url));
                    }
                }, $result) || $result['robust'];
        }
        if ($on) {
            array_push($result['notes'], ...$this->moduleNotes('opensimsearch'));
        }

        return $result;
    }

    /**
     * The master of the simulators follows the OpenSim.ini of the grid, where a grid that has one keeps what it decides.
     *
     * @param  array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>} $result
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>}
     */
    private function finish(array $result): array
    {
        if ($result['error'] === null && $this->layered() && $this->grid->coreDirectory() !== '') {
            (new SimulatorMaster())->ensure($this->grid->directory(), $this->grid->coreDirectory() . '/bin');
        }

        return $result;
    }

    /** Whether the grid has a master for its simulators: what it decides is in its OpenSim.ini, its simulators say what is their own. */
    private function layered(): bool
    {
        return SimulatorMaster::exists($this->grid->directory());
    }

    /** The path of a page of the helpers on the site of the grid (/search, /profile), empty when the grid has no helpers. */
    private function pagePath(string $page): string
    {
        return (string) (parse_url($this->grid->helperUrl($page), PHP_URL_PATH) ?? '');
    }

    /**
     * @param  array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>} $result
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>}
     */
    private function economy(bool $on, string $money, string $prefix, array $result): array
    {
        $module = self::MONEY[$money] ?? self::MONEY['beta'];
        $this->eachFile($result, function (IniEditor $ini, bool $grid) use ($on, $money, $module): void {
            if (!$on) {
                // OpenSimulator has the beta module when it is not told another one
                $ini->comment('Economy', 'economymodule');
                if ($ini->get('Gloebit', 'Enabled') !== null) {
                    $ini->set('Gloebit', 'Enabled', 'false');
                }

                return;
            }
            $this->put($ini, $grid, 'Economy', 'economymodule', $module);
            if ($money === 'beta') {
                $this->put($ini, $grid, 'Economy', 'SellEnabled', 'true');
                if ($ini->get('Gloebit', 'Enabled') !== null) {
                    $ini->set('Gloebit', 'Enabled', 'false');
                }

                return;
            }
            $this->put($ini, $grid, 'Gloebit', 'Enabled', 'true');
            if (($this->layered() ? $grid : !$grid) && $ini->get('Gloebit', 'GLBEnvironment') === null) {
                $ini->set('Gloebit', 'GLBEnvironment', 'sandbox');
            }
        });

        if ($on && $money === 'gloebit') {
            // The currency is served by the helpers: the viewers are told where
            $economy = $prefix !== '' ? $prefix : $this->economyUrl();
            if ($economy !== '') {
                $result['robust'] = $this->robust(
                    fn(IniEditor $ini) => $ini->set('GridInfoService', 'economy', $this->quote($economy)),
                    $result,
                );
            }
            array_push($result['notes'], ...$this->moduleNotes('gloebit'));
            $missing = [];
            // What a simulator says, else what its grid says when it has a master
            $general = $this->layered() ? IniEditor::load($this->general()) : null;
            foreach ([null, ...array_values($this->simulators())] as $file) {
                $ini = $file === null ? $general : IniEditor::load($file);
                if ($ini === null) {
                    continue;
                }
                foreach (['GLBKey', 'GLBSecret', 'GLBOwnerName'] as $key) {
                    if (
                        $ini->get('Gloebit', $key) === null &&
                        ($file === null || $general?->get('Gloebit', $key) === null)
                    ) {
                        $missing[$key] = true;
                    }
                }
            }
            if ($missing !== []) {
                $result['notes'][] =
                    'the keys of your Gloebit application are yours to give: ' .
                    implode(', ', array_keys($missing)) .
                    ' in [Gloebit] of the OpenSim.ini of the grid or of the simulators (Gloebit.ini.example), the module does nothing without them';
            }
        }

        return $result;
    }

    /**
     * @param  array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>} $result
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>}
     */
    private function offline(bool $on, string $mode, string $prefix, array $result): array
    {
        // The module V2 of a standalone keeps the messages in its own process, it is given no address
        $local = $mode === 'robust' && $this->grid->isStandalone();
        $url = match (true) {
            $local => '',
            $mode === 'helpers' => $prefix !== '' ? "$prefix/offline.php" : $this->grid->helperUrl('offline'),
            default => '${Const|PrivURL}:${Const|PrivatePort}',
        };
        if ($on && $url === '' && !$local) {
            $result['error'] =
                "the grid {$this->grid->nick()} has no helpers: the module V1 keeps its messages with them, " .
                "opensim enable {$this->grid->nick()} offline --mode=robust uses the service of Robust";

            return $result;
        }
        $this->eachFile($result, function (IniEditor $ini, bool $grid) use ($on, $mode, $url): void {
            if ($on) {
                $this->put($ini, $grid, 'Messaging', 'OfflineMessageModule', self::OFFLINE_MODULES[$mode]);
                $url === ''
                    ? $ini->comment('Messaging', 'OfflineMessageURL')
                    : $this->put($ini, $grid, 'Messaging', 'OfflineMessageURL', $this->quote($url));

                return;
            }
            $ini->comment('Messaging', 'OfflineMessageModule');
            $ini->comment('Messaging', 'OfflineMessageURL');
        });
        // The service of Robust goes with the module V2: its connector and nothing else (the module is the simulators')
        if ($mode === 'robust' && !$local) {
            $result['robust'] =
                $this->robust(function (IniEditor $ini) use ($on): void {
                    $on
                        ? $ini->uncomment('OfflineIMServiceConnector')
                        : $ini->comment('ServiceList', 'OfflineIMServiceConnector');
                }, $result) || $result['robust'];
        }

        return $result;
    }

    /**
     * @param  array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>} $result
     * @return array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>}
     */
    private function profiles(bool $on, array $result): array
    {
        $this->eachFile($result, function (IniEditor $ini, bool $grid) use ($on): void {
            if ($on) {
                $this->put($ini, $grid, 'UserProfiles', 'ProfileServiceURL', '"${Const|BaseURL}:${Const|PublicPort}"');
            } else {
                $ini->comment('UserProfiles', 'ProfileServiceURL');
            }
        });
        // The service is Robust's: its connector and its switch, and the page of the profiles the viewers open
        $result['robust'] = $this->robust(function (IniEditor $ini) use ($on): void {
            if ($on && !$this->grid->isStandalone()) {
                $ini->uncomment('UserProfilesServiceConnector');
            }
            $ini->set('UserProfilesService', 'Enabled', $on ? 'true' : 'false');
            $path = $this->pagePath('profile');
            if (!$on) {
                $ini->comment('GridInfoService', 'web_profile_url');
            } elseif ($path !== '' && ($ini->get('GridInfoService', 'web_profile_url') ?? '') === '') {
                $ini->set(
                    'GridInfoService',
                    'web_profile_url',
                    $this->quote('${Const|WebURL}' . $path . '/[AGENT_NAME]'),
                );
            }
        }, $result);

        return $result;
    }

    /**
     * Edit the ini file of each simulator of the grid and the OpenSim.ini of the grid, and keep which ones changed.
     *
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>} $result
     * @param callable(Ini,bool):void $edit given the file and whether it is the OpenSim.ini of the grid
     */
    private function eachFile(array &$result, callable $edit): void
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
     * Set a setting in a file. In the OpenSim.ini of the grid, which the simulators do not read unless the grid has a master,
     * only a setting that is there (or was, commented out) is changed: it is there for the helpers, which read it first, and it
     * must say what the simulators say. With a master it is the other way: the grid has the setting, a simulator only the ones it
     * already says.
     */
    private function put(IniEditor $ini, bool $grid, string $section, string $key, string $value): void
    {
        // Without a master the simulators have the settings and the OpenSim.ini of the grid only follows them; with one the
        // grid has them and a simulator only follows them for what it says itself
        if (($this->layered() ? !$grid : $grid) && !$ini->mentions($section, $key)) {
            return;
        }
        $ini->set($section, $key, $value);
    }

    /**
     * Edit the Robust config of the grid, when it is on this machine.
     *
     * @param array{error:?string,simulators:list<string>,grid:bool,robust:bool,notes:list<string>} $result
     * @param callable(Ini):void $edit
     */
    private function robust(callable $edit, array &$result): bool
    {
        $ini = $this->servicesFile();
        if ($this->grid->isRemote() || $ini === null || !is_file($ini)) {
            $result[
                'notes'
            ][] = "Robust of {$this->grid->nick()} is not on this machine: the same change is to make in its config there";

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

    /** The address of the economy the grid gives its viewers, else where its helpers are. */
    private function economyUrl(): string
    {
        $ini = $this->servicesFile();
        $current = $ini !== null && is_file($ini) ? IniEditor::load($ini)->get('GridInfoService', 'economy') : null;
        if ($current !== null && $current !== '') {
            return $current;
        }
        $currency = $this->grid->helperUrl('currency');

        return $currency === '' ? '' : dirname($currency);
    }

    /**
     * What the user must know of a module: that it is not enabled for the install, or not installed.
     *
     * @return list<string>
     */
    private function moduleNotes(string $module): array
    {
        $enabled = $this->grid->environment()->layout->enabledModules();
        $notes = [];
        if (!in_array($module, $enabled, true)) {
            $notes[] = "the module $module is not in EnabledModules of opensim.conf, the simulators do not load it";
        }
        $root = $this->grid->environment()->layout->modulesRoot();
        $core = basename($this->grid->environment()->layout->coreDirectory());
        if ($core !== '' && !is_dir("$root/$core/$module")) {
            $notes[] = "the module $module is not installed for $core (apt install opensim-$core-$module)";
        }

        return $notes;
    }

    private function quote(string $value): string
    {
        return '"' . $value . '"';
    }
}
