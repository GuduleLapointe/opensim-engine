<?php

namespace OpenSim\Provisioning;

use OpenSim\Forms\FieldSet;
use OpenSim\Models\Grid;
use OpenSim\Runtime\Environment;

/**
 * The quick setup: a few answers (the type, the name, the login address, the hypergrid, the avatar that owns it, its password and its email) and
 * the whole setup they stand for, with the proposals of the planners for everything else, in the format of the setup files
 * (see SetupFile). What is wrong or missing is told by the name of the question. Nothing is written: whoever asks shows the
 * setup, asks again what is wrong, and applies it.
 *
 * A grid is made of its Robust and a first simulator, `Welcome`, with its region. A standalone is one simulator that is the
 * whole grid: it has the name that was given, and so does its region.
 */
final class QuickSetup
{
    public const TYPES = ['grid', 'standalone'];

    /** The questions the planners' fields answer, by the name of the field. */
    private const QUESTIONS = [
        'name' => 'name',
        'hostname' => 'login_uri',
        'public_port' => 'login_uri',
        'new_name' => 'avatar',
        'new_password' => 'password',
        'new_email' => 'email',
    ];

    /**
     * @param array<string,mixed> $answers type, name, login_uri, hypergrid, avatar, password, email: all optional, a missing one is proposed
     *        or, when it cannot be, told in the problems
     * @return array{valid:bool,problems:array<string,string>,corrected:array<string,string>,setup:array<string,mixed>,rows:list<array{section:string,label:string,value:string}>}
     */
    public static function plan(Environment $environment, array $answers): array
    {
        $problems = [];
        $corrected = [];
        $type = strtolower(trim((string) ($answers['type'] ?? '')));
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            $problems['type'] = _('invalid');
        }
        $standalone = $type === 'standalone';

        // The grid. What is right or wrong, and what is corrected, is said by the fields of the models: the answers go to them as given,
        // and the plan is made of the corrected ones (the person confirms a correction by sending the form again).
        $gridAnswers = ['standalone' => $standalone, 'hypergrid' => $answers['hypergrid'] ?? true];
        trim((string) ($answers['name'] ?? '')) !== '' && ($gridAnswers['name'] = trim((string) $answers['name']));
        [$uri, $uriProblem] = Grid::parseLoginUri((string) ($answers['login_uri'] ?? ''));
        $uriProblem !== null && ($problems['login_uri'] = $uriProblem);
        $gridAnswers += $uri;
        $planner = GridPlanner::forMachine($environment);
        $fixes = self::review($planner->fields(), $planner->problems($gridAnswers), $gridAnswers, $problems, $corrected);
        $plan = $planner->plan($fixes + $gridAnswers);

        // Its first simulator, with its region, and the avatar that owns the estate
        $ui = new NullUi();
        $database = new DatabaseService($ui);
        $grid = Grid::planned($environment, $plan);
        $region = $standalone ? $plan->gridName : 'Welcome';
        $simulators = SimulatorPlanner::forGrid($grid, $database, $ui, null, $standalone ? $plan->gridName : null);
        $owner = [
            'estate_owner' => '+',
            'new_name' => trim((string) ($answers['avatar'] ?? '')),
            'new_password' => (string) ($answers['password'] ?? ''),
            'new_email' => trim((string) ($answers['email'] ?? '')),
        ];
        $fixes = self::review($simulators->fields(), $simulators->problems($owner), $owner, $problems, $corrected);
        $simulator = $simulators->plan($fixes + $owner);
        $regions = RegionPlanner::forSimulator($simulator, $grid, $database, defaultName: $region);
        self::review($regions->fields(), $regions->problems(['name' => $region]), ['name' => $region], $problems, $corrected);
        $regions->apply($simulator, ['name' => $region]);

        return [
            'valid' => $problems === [],
            'problems' => $problems,
            'corrected' => $corrected,
            'setup' => SetupFile::withSimulator(SetupFile::withGrid([], $plan), $simulator),
            'rows' => self::rows($plan, $simulator, $standalone, trim((string) ($answers['email'] ?? ''))),
        ];
    }

    /**
     * Takes what the fields of a model say of some answers: the problems and the corrections, under the name of the question they
     * answer. Nothing is decided here, the models' fields do.
     *
     * @param array<string,string> $fieldProblems what the planner found, by field
     * @param array<string,mixed> $given
     * @param array<string,string> $problems the problems of the questions, completed
     * @param array<string,string> $corrected the corrections of the questions, completed
     * @return array<string,string> the corrected values, by field
     */
    private static function review(FieldSet $fields, array $fieldProblems, array $given, array &$problems, array &$corrected): array
    {
        foreach ($fieldProblems as $field => $message) {
            $problems[self::QUESTIONS[$field] ?? $field] ??= $message;
        }
        $fixes = $fields->corrections($given);
        foreach ($fixes as $field => $value) {
            $corrected[self::QUESTIONS[$field] ?? $field] = $value;
        }

        return $fixes;
    }

    /**
     * Every value the setup defines, in the order a person reads them, by section (grid, simulator, region, owner): what the planners
     * proposed and the answers, so that nothing the setup will do is a surprise.
     *
     * @return list<array{section:string,label:string,value:string}>
     */
    private static function rows(GridPlan $plan, SimulatorPlan $simulator, bool $standalone, string $email): array
    {
        $rows = [];
        $add = static function (string $section, string $label, string $value) use (&$rows): void {
            $rows[] = ['section' => $section, 'label' => $label, 'value' => $value];
        };
        $yes = static fn(bool $value): string => $value ? 'yes' : 'no';
        $console = static fn(string $mode, int $port, string $user): string => $mode === 'rest'
            ? "remote, port $port, user $user"
            : 'screen session';
        $database = static fn(string $name, string $host, string $user): string => "$name @ $host (user $user)";

        $add('grid', 'Grid name', "{$plan->gridName} ({$plan->gridNick})");
        $add('grid', 'Login URI', "http://{$plan->baseHostname}:{$plan->publicPort}");
        $add('grid', 'Hypergrid', $yes($plan->enableHypergrid));
        $add('grid', 'Region spacing', $plan->regionSpacing === 0 ? 'side by side' : "{$plan->regionSpacing} free block(s)");
        $plan->coreDirectory !== '' && $add('grid', 'Core', $plan->coreDirectory);
        $standalone || $add('grid', 'Ports', "public {$plan->publicPort}, private {$plan->privatePort}");
        $standalone || $add('grid', 'Console', $console($plan->consoleMode, $plan->consolePort, $plan->consoleUser));
        $add('grid', 'Web URL', $plan->webUrl);
        $add('grid', 'Helpers', $plan->helpers ? "served at {$plan->webUrl}{$plan->helpersPath}" : 'not served by this web site');
        $add('grid', 'Search', ['internal' => 'this grid (opensim-helpers)', 'shared' => 'shared provider (2do.directory)', 'disabled' => 'none'][$plan->search] ?? $plan->search);
        $add('grid', 'Currency', $plan->economy === 'gloebit' ? 'Gloebit' : 'BetaGridLikeMoneyModule');
        $add('grid', 'OSSL', $plan->ossl ? 'more functions for parcel owners' : 'the defaults of the core');
        $plan->helpers && $add('grid', 'Web server', ['caddy' => 'Caddy', 'apache' => 'Apache', 'nginx' => 'nginx'][$plan->webServer] ?? $plan->webServer);
        $add('grid', $standalone ? 'Database' : 'Grid database', $database($plan->dbName, $plan->dbHost, $plan->dbUser));
        $standalone || $add('grid', 'Robust config', $plan->robustIni());
        $add('grid', 'Grid folder', $plan->etcDirectory);

        $add('simulator', 'Simulator', "{$simulator->simulatorName} ({$simulator->slug})");
        $add('simulator', 'HTTP port', (string) $simulator->httpPort);
        $add('simulator', 'Console', $console($simulator->consoleMode, $simulator->consolePort, $simulator->consoleUser));
        $standalone || $add('simulator', 'Simulator database', $database($simulator->dbName, $simulator->dbHost, $simulator->dbUser));
        $add('simulator', 'Estate', $simulator->estateName);
        $add('simulator', 'Estate owner', $simulator->estateOwner . ($simulator->createOwner ? ' (account to create)' : ''));
        $add('simulator', 'Simulator config', $simulator->iniPath());

        $add('region', 'Region', $simulator->regionName);
        $add('region', 'Location', $simulator->regionLocation);
        $add('region', 'Port', (string) $simulator->regionPort);
        $simulator->regionRoles !== [] && $add('region', 'Roles', implode(', ', $simulator->regionRoles));

        $add('owner', 'Avatar', $simulator->estateOwner . ($email !== '' ? " <$email>" : ''));

        return $rows;
    }
}
