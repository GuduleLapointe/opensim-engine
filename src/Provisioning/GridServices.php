<?php

namespace OpenSim\Provisioning;

use OpenSim\Config\IniEditor;

/**
 * What a grid tells its viewers and proposes to its users, in the config of its services: the names, the addresses of the helpers,
 * the hypergrid, the welcome message. The config of the services is the Robust.HG.ini of a grid, the StandaloneCommon.ini of the
 * grid for a standalone, which has no Robust.
 */
final class GridServices
{
    /**
     * The names and the addresses of the grid, always set from the plan.
     *
     * @param array<string,array<string,string>> $extra more settings of the same kind
     */
    public function identity(IniEditor $ini, GridPlan $plan): void
    {
        $ini->merge([
            'LoginService' => ['DestinationGuide' => self::q('${Const|WebURL}/guide')],
            'GridInfoService' => [
                'gridname' => self::q($plan->gridName),
                'gridnick' => self::q($plan->gridNick),
                'welcome' => self::q('${Const|WebURL}'),
            ],
        ]);
    }

    /**
     * Everything else: the hypergrid, and what the setup proposes (set when the grid has nothing for it, never over what it has).
     * What the viewers are told of the helpers, the search, the profiles are options (see GridOptions).
     *
     * @param ?IniEditor $existing the config as it was, null for a grid that is made
     * @param ?IniEditor $baseline what the distribution says: a setting the config still has as the distribution says it is not the
     *        operator's
     */
    public function apply(IniEditor $ini, GridPlan $plan, ?IniEditor $existing, ?IniEditor $baseline = null): void
    {
        if ($plan->enableHypergrid) {
            $ini->merge([
                'Hypergrid' => [
                    'HomeURI' => self::q('${Const|BaseURL}:${Const|PublicPort}'),
                    'GatekeeperURI' => self::q('${Const|BaseURL}:${Const|PublicPort}'),
                ],
            ]);
            // The gatekeeper the viewers and the other grids read in get_grid_info, when the grid has not set another
            if (($ini->get('GridInfoService', 'gatekeeper') ?? '') === '') {
                $ini->set('GridInfoService', 'gatekeeper', '${Const|BaseURL}:${Const|PublicPort}');
            }
        }

        // What the setup proposes, which the operator may change
        $proposed = [
            // Shown to the user when logging in (<USERNAME> is replaced by the name of the avatar)
            ['LoginService', 'WelcomeMessage', self::q("Welcome to {$plan->gridName}, <USERNAME>!")],
            ['LibraryService', 'LibraryName', self::q("{$plan->gridName} Library")],
        ];
        foreach ($proposed as [$section, $key, $value]) {
            $was = $existing?->get($section, $key);
            if ($was === null || $was === $baseline?->get($section, $key)) {
                $ini->set($section, $key, $value);
            }
        }
        $this->alignSearch($ini);
    }

    /**
     * Make the services of a standalone: its StandaloneCommon.ini, the copy of the grid, says what the Robust.HG.ini of a grid says.
     *
     * @return ?string the path when written
     */
    public function writeStandalone(GridPlan $plan): ?string
    {
        $path = $plan->etcDirectory . '/config-include/StandaloneCommon.ini';
        if (!is_file($path)) {
            return null;
        }
        $distribution = null;
        foreach (['StandaloneCommon.ini', 'StandaloneCommon.ini.example'] as $name) {
            if (is_file("{$plan->binDir}/config-include/$name")) {
                $distribution = IniEditor::load("{$plan->binDir}/config-include/$name");

                break;
            }
        }
        $existing = IniEditor::load($path);
        $ini = IniEditor::load($path);
        $this->identity($ini, $plan);
        $this->apply($ini, $plan, $existing, $distribution);
        if ($ini->toString() === $existing->toString()) {
            return null;
        }
        $ini->save($path);

        return $path;
    }

    /**
     * The search of the web has two settings: `[LoginService] SearchURL` (documented since 0.7.1, sent to the viewer at login
     * and as a feature of the grid) and `[GridInfoService] search` (what get_grid_info says, which the viewers read). Until it is
     * known which one each viewer takes, a value given to one is given to the other when it has none; `search` is the real one,
     * the one the helpers use. Two values that differ are the operator's.
     */
    public function alignSearch(IniEditor $ini): void
    {
        $search = $ini->get('GridInfoService', 'search') ?? '';
        $url = $ini->get('LoginService', 'SearchURL') ?? '';
        if ($search !== '' && $url === '') {
            $ini->set('LoginService', 'SearchURL', self::q($search));
        } elseif ($url !== '' && $search === '') {
            $ini->set('GridInfoService', 'search', self::q($url));
        }
    }

    private static function q(string $value): string
    {
        return '"' . $value . '"';
    }
}
