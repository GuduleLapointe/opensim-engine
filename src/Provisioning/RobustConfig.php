<?php

namespace OpenSim\Provisioning;

use OpenSim\Config\IniEditor;

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
                'AssetLoaderArgs' => $this->q('${Const|DataDirectory}/assets/AssetSets.xml'),
            ],
            'GridService' => [
                'MapTileDirectory' => $this->q('${Const|CacheDirectory}/maptiles'),
            ],
            'LibraryService' => [
                'DefaultLibrary' => $this->q('${Const|DataDirectory}/inventory/Libraries.xml'),
            ],
            'MapImageService' => [
                'TilesStoragePath' => $this->q('${Const|DataDirectory}/maptiles'),
            ],
            'BakedTextureService' => [
                'BaseDirectory' => $this->q('${Const|CacheDirectory}/bakes'),
            ],
        ]);

        $services = new GridServices();
        $services->identity($ini, $plan);
        $services->apply($ini, $plan, $existing);

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

        if ($existing === null) {
            foreach (self::connectors($plan->helpers, $plan->enableHypergrid, $plan->offline) as $connector) {
                $ini->uncomment($connector);
            }
        }

        return $ini->toString();
    }

    private function q(string $value): string
    {
        return '"' . $value . '"';
    }
}
