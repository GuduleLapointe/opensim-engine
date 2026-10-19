<?php

namespace OpenSim\Provisioning;

use OpenSim\Config\IniEditor;
use OpenSim\Provisioning\Web\Services;

/**
 * Generates a grid's Robust.HG.ini by overlaying our settings onto the
 * distribution's Robust(.HG).ini.example, preserving its comments and defaults.
 *
 * No [Launch] section: that was a pre-Const artifact. Everything lives in the
 * standard [Const]/[Startup]/… sections; the launcher reads BinDirectory from
 * [Const] and derives the rest.
 */
final class RobustConfig
{
    /**
     * The optional connectors of Robust (commented in the distribution) that the setup serves in a grid it makes, each with its
     * counterpart in the simulators (see SimulatorConfig::gridLayer() and GridShared): the bakes of the avatars, the estates of the
     * grid, the profiles, the offline messages of Robust (the module V2), the groups (with the helpers or a hypergrid) and the groups of the hypergrid. A grid that exists is not
     * changed: it is the operator's (and `opensim enable`) to decide.
     *
     * @return list<string> the keys of [ServiceList]
     */
    public static function connectors(bool $helpers, bool $hypergrid, string $offline = ''): array
    {
        return array_values(
            array_filter([
                'BakedTextureService',
                'EstateDataService',
                'UserProfilesServiceConnector',
                in_array($offline, ['robust', 'both'], true) ? 'OfflineIMServiceConnector' : null,
                $helpers || $hypergrid ? 'GroupsServiceConnector' : null,
                $hypergrid ? 'HGGroupsServiceConnector' : null,
            ]),
        );
    }

    public function example(GridPlan $plan): string
    {
        return $plan->binDir . '/' . ($plan->enableHypergrid ? 'Robust.HG.ini.example' : 'Robust.ini.example');
    }

    public function generate(GridPlan $plan): string
    {
        // The distribution, over it what the grid has set (a setting new in the distribution is there, what the operator chose
        // stays), over it what the setup manages
        $current = is_file($plan->robustIni()) ? $plan->robustIni() : null;
        $existing = $current === null ? null : IniEditor::load($current);
        $ini = IniEditor::layered($this->example($plan), $current);

        $ini->merge([
            'Const' => [
                'BaseHostname' => $this->q($plan->baseHostname),
                'BaseURL' => $this->q("http://{$plan->baseHostname}"),
                'WebURL' => $this->q($plan->webUrl),
                'PublicPort' => (string) $plan->publicPort,
                'PrivatePort' => (string) $plan->privatePort,
                'EtcDirectory' => $this->q($plan->etcDirectory),
                'DataDirectory' => $this->q($plan->dataDirectory),
                'CacheDirectory' => $this->q($plan->cacheDirectory),
                'LogsDirectory' => $this->q($plan->logsDirectory),
            ],
            'Startup' => [
                'PIDFile' => $this->q('${Const|CacheDirectory}/' . $plan->gridNick . '.pid'),
                'RegistryLocation' => $this->q('${Const|DataDirectory}/registry'),
                'ConfigDirectory' => $this->q('${Const|EtcDirectory}/robust-include'),
                'ConsoleHistoryFile' => $this->q(
                    '${Const|LogsDirectory}/' . $plan->gridNick . '.RobustConsoleHistory.txt',
                ),
            ],
            'DatabaseService' => [
                'ConnectionString' => $this->q($plan->connectionString()),
            ],
            'AssetService' => [
                'BaseDirectory' => $this->q('${Const|DataDirectory}/fsassets/data'),
                'SpoolDirectory' => $this->q('${Const|CacheDirectory}/fsassets/tmp'),
                'AssetLoaderArgs' => $this->q('${Const|EtcDirectory}/assets/AssetSets.xml'),
            ],
            'GridService' => [
                'MapTileDirectory' => $this->q('${Const|CacheDirectory}/maptiles'),
            ],
            'LibraryService' => [
                'DefaultLibrary' => $this->q('${Const|EtcDirectory}/inventory/Libraries.xml'),
            ],
            'LoginService' => [
                'DestinationGuide' => $this->q('${Const|WebURL}/guide'),
            ],
            'MapImageService' => [
                'TilesStoragePath' => $this->q('${Const|DataDirectory}/maptiles'),
            ],
            'GridInfoService' => [
                'gridname' => $this->q($plan->gridName),
                'gridnick' => $this->q($plan->gridNick),
                'welcome' => $this->q('${Const|WebURL}'),
            ],
            'BakedTextureService' => [
                'BaseDirectory' => $this->q('${Const|CacheDirectory}/bakes'),
            ],
        ]);

        // What the viewers are told of the services of the helpers: the official settings, which the helpers read back
        if ($plan->helpers) {
            $services = new Services($plan->helpersPath);
            // The economy is the path of the helpers: the viewer adds the name of the script (currency.php...)
            $ini->set('GridInfoService', 'economy', $this->q('${Const|WebURL}' . $services->base()));
            // The splash page the viewer shows before the login
            $ini->set('GridInfoService', 'welcome', $this->q('${Const|WebURL}' . $services->path('welcome')));
            // The destination guide of the viewers is the one of the helpers
            $ini->set('LoginService', 'DestinationGuide', $this->q('${Const|WebURL}' . $services->path('guide')));
            // The profile page: the viewers replace [AGENT_NAME] by Firstname.Lastname (the helpers also take Firstname-Lastname)
            $ini->set(
                'GridInfoService',
                'web_profile_url',
                $this->q('${Const|WebURL}' . $services->path('profile') . '/[AGENT_NAME]'),
            );
            // The message of the day: the text of motd.php (WelcomeMessage), read when Robust starts
            $ini->set('LoginService', 'MessageUrl', $this->q('${Const|WebURL}' . $services->path('motd')));
            // The search of the web, when the grid has none of the two settings that say it (alignSearch() gives one to the other); not a
            // grid that has no search
            if (
                $plan->search !== 'disabled' &&
                ($ini->get('GridInfoService', 'search') ?? '') === '' &&
                ($ini->get('LoginService', 'SearchURL') ?? '') === ''
            ) {
                $ini->set('GridInfoService', 'search', $this->q('${Const|WebURL}' . $services->path('search')));
            }
        }

        if ($plan->enableHypergrid) {
            $ini->merge([
                'Hypergrid' => [
                    'HomeURI' => $this->q('${Const|BaseURL}:${Const|PublicPort}'),
                    'GatekeeperURI' => $this->q('${Const|BaseURL}:${Const|PublicPort}'),
                ],
            ]);
            // The gatekeeper the viewers and the other grids read in get_grid_info, when the grid has not set another
            if (($ini->get('GridInfoService', 'gatekeeper') ?? '') === '') {
                $ini->set('GridInfoService', 'gatekeeper', '${Const|BaseURL}:${Const|PublicPort}');
            }
        }

        // The remote console, every right on Robust through its port
        if ($plan->consoleMode === 'rest' && $plan->consolePort > 0) {
            $ini->merge([
                'Network' => [
                    'ConsoleHost' => $this->q($plan->consoleHost),
                    'ConsoleUser' => $this->q($plan->consoleUser),
                    'ConsolePass' => $this->q($plan->consolePass),
                    'ConsolePort' => (string) $plan->consolePort,
                ],
            ]);
        }

        // What the setup proposes, which the operator may change: set when the grid has nothing for it, never over what it has
        $proposed = [
            // Shown to the user when logging in (<USERNAME> is replaced by the name of the avatar)
            ['LoginService', 'WelcomeMessage', $this->q("Welcome to {$plan->gridName}, <USERNAME>!")],
            ['LibraryService', 'LibraryName', $this->q("{$plan->gridName} Library")],
            // User profiles (connector + service)
            ['UserProfilesService', 'Enabled', 'true'],
        ];
        foreach ($proposed as [$section, $key, $value]) {
            if ($existing?->get($section, $key) === null) {
                $ini->set($section, $key, $value);
            }
        }
        if ($existing === null) {
            foreach (self::connectors($plan->helpers, $plan->enableHypergrid, $plan->offline) as $connector) {
                $ini->uncomment($connector);
            }
        }
        $this->alignSearch($ini);

        return $ini->toString();
    }

    /**
     * The search of the web has two settings: `[LoginService] SearchURL` (documented since 0.7.1, sent to the viewer at login
     * and as a feature of the grid) and `[GridInfoService] search` (what get_grid_info says, which the viewers read). Until it is
     * known which one each viewer takes, a value given to one is given to the other when it has none; `search` is the real one,
     * the one the helpers use. Two values that differ are the operator's.
     */
    private function alignSearch(IniEditor $ini): void
    {
        $search = $ini->get('GridInfoService', 'search') ?? '';
        $url = $ini->get('LoginService', 'SearchURL') ?? '';
        if ($search !== '' && $url === '') {
            $ini->set('LoginService', 'SearchURL', $this->q($search));
        } elseif ($url !== '' && $search === '') {
            $ini->set('GridInfoService', 'search', $this->q($url));
        }
    }

    private function q(string $value): string
    {
        return '"' . $value . '"';
    }
}
