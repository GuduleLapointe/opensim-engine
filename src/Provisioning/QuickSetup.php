<?php

namespace OpenSim\Provisioning;

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
     * @return array{valid:bool,problems:array<string,string>,setup:array<string,mixed>}
     */
    public static function plan(Environment $environment, array $answers): array
    {
        $problems = [];
        $type = strtolower(trim((string) ($answers['type'] ?? '')));
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            $problems['type'] = sprintf(_('One of: %s.'), implode(', ', self::TYPES));
        }
        $standalone = $type === 'standalone';

        // The grid
        $gridAnswers = ['standalone' => $standalone];
        // The hypergrid is on unless it is said to be off
        $hypergrid = filter_var($answers['hypergrid'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $hypergrid === null
            ? ($problems['hypergrid'] = _('Yes or no.'))
            : ($gridAnswers['hypergrid'] = $hypergrid);
        trim((string) ($answers['name'] ?? '')) !== '' && ($gridAnswers['name'] = trim((string) $answers['name']));
        [$uri, $uriProblem] = self::loginUri((string) ($answers['login_uri'] ?? ''));
        $uriProblem !== null && ($problems['login_uri'] = $uriProblem);
        $gridAnswers += $uri;
        $planner = GridPlanner::forMachine($environment);
        foreach ($planner->problems($gridAnswers) as $field => $message) {
            $problems[self::QUESTIONS[$field] ?? $field] ??= $message;
        }
        $plan = $planner->plan($gridAnswers);

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
        foreach ($simulators->problems($owner) as $field => $message) {
            $problems[self::QUESTIONS[$field] ?? $field] ??= $message;
        }
        $simulator = $simulators->plan($owner);
        RegionPlanner::forSimulator($simulator, $grid, $database, defaultName: $region)->apply($simulator, [
            'name' => $region,
        ]);

        return [
            'valid' => $problems === [],
            'problems' => $problems,
            'setup' => SetupFile::withSimulator(SetupFile::withGrid([], $plan), $simulator),
        ];
    }

    /**
     * The host and the port a login address says; nothing for what it does not say (the planner proposes it).
     *
     * @return array{0:array<string,mixed>,1:?string} the answers for the grid, the problem
     */
    private static function loginUri(string $uri): array
    {
        $uri = trim($uri);
        if ($uri === '') {
            return [[], null];
        }
        $parts = parse_url(str_contains($uri, '://') ? $uri : "http://$uri");
        if ($parts === false || ($parts['host'] ?? '') === '') {
            return [[], _('An address like http://login.example.org:8002.')];
        }
        $found = ['hostname' => $parts['host']];
        isset($parts['port']) && ($found['public_port'] = $parts['port']);
        if (strtolower($parts['scheme'] ?? 'http') !== 'http') {
            return [$found, _('Only http is handled for now: http://login.example.org:8002.')];
        }

        return [$found, null];
    }
}
