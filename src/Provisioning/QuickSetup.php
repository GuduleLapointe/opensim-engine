<?php

namespace OpenSim\Provisioning;

use OpenSim\Exceptions\SetupFailedException;
use OpenSim\Forms\FieldSet;
use OpenSim\Models\Grid;
use OpenSim\Result;
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

    /**
     * The options of the form, in the order they are shown. They are the only choices of a quick setup: everything else is on (the
     * helpers, the search module, the offline messages, the profiles, the destination guide, the grid status). `plan` says what an
     * option sets in the plan of the grid (nothing yet for SSL), `implemented` false that the setup does not apply it yet.
     *
     * @return array<string,array{label:string,default:bool,implemented:bool,plan:?\Closure}>
     */
    private static function options(): array
    {
        return [
            'ssl' => ['label' => 'SSL', 'default' => false, 'implemented' => false, 'plan' => null],
            'hypergrid' => [
                'label' => 'Hypergrid',
                'default' => true,
                'implemented' => true,
                'plan' => static fn(bool $on): array => ['hypergrid' => $on],
            ],
            'search' => [
                'label' => 'Cross-grid Search',
                'default' => false,
                'implemented' => true,
                'plan' => static fn(bool $on): array => ['search' => $on ? 'shared' : 'internal'],
            ],
            'gloebit' => [
                'label' => 'Economy: Gloebit',
                'default' => false,
                'implemented' => true,
                'plan' => static fn(bool $on): array => ['economy' => $on ? 'gloebit' : 'beta'],
            ],
            'ossl' => [
                'label' => 'Extended OSSL',
                'default' => false,
                'implemented' => true,
                'plan' => static fn(bool $on): array => ['ossl' => $on],
            ],
        ];
    }

    /**
     * What can be set up: the grid and the standalone, a simulator that joins another grid, and the presets there are.
     *
     * @return list<array{name:string,label:string,implemented:bool}>
     */
    private static function types(Environment $environment): array
    {
        $types = [
            ['name' => 'grid', 'label' => 'Grid', 'implemented' => true],
            ['name' => 'standalone', 'label' => 'Standalone', 'implemented' => true],
            ['name' => 'simulator', 'label' => 'Simulator (join other grid)', 'implemented' => false],
        ];
        // The presets planned stay in the list, the ones this machine has are added
        $presets = ['speculoos' => 'Speculoos', 'osgrid' => 'OSGrid'] + (new Presets([], $environment->layout->etcRoot()))->names();
        foreach ($presets as $name => $grid) {
            $types[] = ['name' => (string) $name, 'label' => $grid, 'implemented' => false];
        }

        return $types;
    }

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
     * @param array<string,mixed> $answers type, name, login_uri, avatar, password, email and the options (ssl, hypergrid, search,
     *        gloebit, ossl): all optional, a missing one is proposed or, when it cannot be, told in the problems
     * @return array{valid:bool,problems:array<string,string>,corrected:array<string,string>,setup:array<string,mixed>,rows:list<array{section:string,label:string,value:string}>,types:list<array{name:string,label:string,implemented:bool}>,options:list<array{name:string,label:string,default:bool,value:bool,implemented:bool}>}
     */
    public static function plan(Environment $environment, array $answers): array
    {
        $built = self::build($environment, $answers);

        return [
            'valid' => $built['problems'] === [],
            'problems' => $built['problems'],
            'corrected' => $built['corrected'],
            'setup' => SetupFile::withSimulator(SetupFile::withGrid([], $built['plan']), $built['simulator']),
            'rows' => self::rows($built['plan'], $built['simulator'], $built['standalone'], trim((string) ($answers['email'] ?? '')), $built['chosen']),
            'types' => $built['types'],
            'options' => array_map(
                static fn(string $name, array $option): array => [
                    'name' => $name,
                    'label' => $option['label'],
                    'default' => $option['default'],
                    'value' => $built['chosen'][$name],
                    'implemented' => $option['implemented'],
                ],
                array_keys($built['options']),
                array_values($built['options']),
            ),
        ];
    }

    /**
     * The plans the answers stand for, and what is wrong with them.
     *
     * @param array<string,mixed> $answers
     * @return array{problems:array<string,string>,corrected:array<string,string>,plan:GridPlan,simulator:SimulatorPlan,standalone:bool,chosen:array<string,bool>,types:list<array{name:string,label:string,implemented:bool}>,options:array<string,array{label:string,default:bool,implemented:bool,plan:?\Closure}>}
     */
    private static function build(Environment $environment, array $answers): array
    {
        $problems = [];
        $corrected = [];
        $type = strtolower(trim((string) ($answers['type'] ?? '')));
        $types = self::types($environment);
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            $problems['type'] = in_array($type, array_column($types, 'name'), true) ? _('not implemented') : _('invalid');
        }
        $standalone = $type === 'standalone';

        // The options: what is chosen, and what each sets in the plan of the grid
        $options = self::options();
        foreach ((new FieldSet(array_map(static fn(string $name): array => ['name' => $name, 'type' => 'boolean'], array_keys($options))))->problems($answers) as $name => $message) {
            $problems[$name] = $message;
        }
        $chosen = [];
        $fromOptions = [];
        foreach ($options as $name => $option) {
            $given = $answers[$name] ?? null;
            $chosen[$name] = Result::isTrue($given) || (Result::isFalse($given) ? false : $option['default']);
            $fromOptions = ($option['plan'] !== null ? ($option['plan'])($chosen[$name]) : []) + $fromOptions;
        }

        // The grid. What is right or wrong, and what is corrected, is said by the fields of the models: the answers go to them as given,
        // and the plan is made of the corrected ones (the person confirms a correction by sending the form again).
        $gridAnswers = ['standalone' => $standalone, 'helpers' => true] + $fromOptions;
        trim((string) ($answers['name'] ?? '')) !== '' && ($gridAnswers['name'] = trim((string) $answers['name']));
        [$uri, $uriProblem] = Grid::parseLoginUri((string) ($answers['login_uri'] ?? ''));
        $uriProblem !== null && ($problems['login_uri'] = $uriProblem);
        $gridAnswers += $uri;
        $planner = GridPlanner::forMachine($environment);
        $fixes = self::review($planner->fields(), $planner->problems($gridAnswers), $gridAnswers, $problems, $corrected);
        $plan = $planner->plan($fixes + $gridAnswers);
        // A new grid: the name of one that exists is taken (its regions, its ports are not the business of this setup)
        $taken = Grid::find($environment, $plan->gridNick) !== null;
        $taken && ($problems['name'] = _('taken'));

        // Its first simulator, with its region, and the avatar that owns the estate
        $ui = new NullUi();
        $database = new DatabaseService($ui);
        $grid = Grid::planned($environment, $plan);
        $region = $standalone ? Grid::standaloneRegionName($plan->gridName) : 'Welcome';
        $simulators = SimulatorPlanner::forGrid($grid, $database, $ui, null, $standalone ? $region : null);
        $owner = [
            'estate_owner' => '+',
            'new_name' => trim((string) ($answers['avatar'] ?? '')),
            'new_password' => (string) ($answers['password'] ?? ''),
            'new_email' => trim((string) ($answers['email'] ?? '')),
        ];
        $fixes = self::review($simulators->fields(), $simulators->problems($owner), $owner, $problems, $corrected);
        $simulator = $simulators->plan($fixes + $owner);
        $regions = RegionPlanner::forSimulator($simulator, $grid, $database, defaultName: $region);
        $taken || self::review($regions->fields(), $regions->problems(['name' => $region]), ['name' => $region], $problems, $corrected);
        $regions->apply($simulator, ['name' => $region]);

        return [
            'problems' => $problems,
            'corrected' => $corrected,
            'plan' => $plan,
            'simulator' => $simulator,
            'standalone' => $standalone,
            'chosen' => $chosen,
            'types' => $types,
            'options' => $options,
        ];
    }

    /**
     * The scenario of the quick setup, one step at a time: a client sends back the screen it was given, filled, and gets the next
     * screen, the same one with what is wrong, or an action to do (quit with a message, abort with an error). It knows nothing else:
     * the order of the screens, the checks, the corrections, Back and Quit are here, so that a terminal, a web page or an app run
     * the same setup. Nothing is kept here: the state is a string the client sends back as it got it.
     *
     * The screens are `menu` (a choice), `form` (fields), `checks` (check boxes) and `review` (what the setup will do, to confirm). The
     * last one, confirmed, makes the folders and writes the files of the grid and of its first simulator.
     *
     * @param array{state?:string,button?:string,answers?:array<string,mixed>} $request button: next, back or quit
     * @return array<string,mixed> `action` screen (kind, title, notice, buttons, state...), quit (message) or abort (error)
     */
    public static function next(Environment $environment, array $request): array
    {
        $state = self::decode((string) ($request['state'] ?? ''));
        $button = in_array($request['button'] ?? 'next', ['next', 'back', 'quit'], true) ? $request['button'] ?? 'next' : 'next';
        $answers = $state['answers'];
        $given = is_array($request['answers'] ?? null) ? $request['answers'] : [];
        if ($state['step'] === '') {
            return self::screenType($environment, $answers, '');
        }
        if ($button === 'quit') {
            return ['action' => 'abort', 'error' => _('Cancelled')];
        }

        switch ($state['step']) {
            case 'type':
                if ($button === 'back') {
                    return ['action' => 'abort', 'error' => _('Cancelled')];
                }
                $type = strtolower(trim((string) ($given['type'] ?? $answers['type'] ?? '')));
                $answers['type'] = $type;
                $known = array_column(self::types($environment), 'implemented', 'name');
                if (($known[$type] ?? false) !== true) {
                    return self::screenType($environment, $answers, self::notImplemented($environment));
                }
                // The proposals of the engine are what the form starts with
                $proposed = self::plan($environment, ['type' => $type])['setup']['grid'];
                $answers += ['name' => $proposed['name'], 'login_uri' => $proposed['hostname'] . ':' . $proposed['public_port']];

                return self::screenForm($answers, '');

            case 'form':
                $answers = self::keep($answers, $given, array_keys(self::fields((string) ($answers['type'] ?? ''))));
                if ($button === 'back') {
                    return self::screenType($environment, $answers, '');
                }

                return self::checkForm($environment, $answers) ?? self::screenOptions($answers, '');

            case 'options':
                $answers = self::keep($answers, $given, array_keys(self::options()), true);
                if ($button === 'back') {
                    return self::screenForm($answers, '');
                }

                return self::checkForm($environment, $answers) ?? self::screenReview($environment, $answers);

            case 'review':
                if ($button === 'back') {
                    return self::screenOptions($answers, '');
                }

                return self::install($environment, $answers);
        }

        return self::screenType($environment, [], '');
    }

    /** The first screen: what can be set up. */
    private static function screenType(Environment $environment, array $answers, string $notice): array
    {
        return [
            'action' => 'screen',
            'kind' => 'menu',
            'name' => 'type',
            'title' => _('OpenSim setup'),
            'text' => '',
            'notice' => $notice,
            'items' => array_map(
                static fn(array $type): array => [
                    'key' => $type['name'],
                    'label' => $type['label'],
                    'note' => $type['implemented'] ? '' : _('not implemented'),
                ],
                self::types($environment),
            ),
            'value' => (string) ($answers['type'] ?? ''),
            'buttons' => ['next' => _('OK'), 'back' => _('Quit')],
            'state' => self::encode('type', $answers),
        ];
    }

    /** The questions of the type chosen, filled with the answers so far. */
    private static function screenForm(array $answers, string $notice): array
    {
        $type = (string) ($answers['type'] ?? '');
        $fields = [];
        foreach (self::fields($type) as $name => [$label, $secret]) {
            $fields[] = ['name' => $name, 'label' => $label, 'value' => (string) ($answers[$name] ?? ''), 'secret' => $secret];
        }

        return [
            'action' => 'screen',
            'kind' => 'form',
            'title' => $type === 'standalone' ? _('Standalone') : _('Grid'),
            'text' => '',
            'notice' => $notice,
            'fields' => $fields,
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('form', $answers),
        ];
    }

    /** The options, each with its proposal or the last choice. */
    private static function screenOptions(array $answers, string $notice): array
    {
        $items = [];
        foreach (self::options() as $name => $option) {
            $given = $answers[$name] ?? null;
            $items[] = [
                'name' => $name,
                'label' => $option['label'],
                'checked' => Result::isTrue($given) || (Result::isFalse($given) ? false : $option['default']),
                'note' => $option['implemented'] ? '' : _('not implemented'),
            ];
        }

        return [
            'action' => 'screen',
            'kind' => 'checks',
            'title' => _('Options'),
            'text' => '',
            'notice' => $notice,
            'items' => $items,
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('options', $answers),
        ];
    }

    /** What the setup will do, to confirm. */
    private static function screenReview(Environment $environment, array $answers): array
    {
        return [
            'action' => 'screen',
            'kind' => 'review',
            'title' => _('Review'),
            'text' => '',
            'notice' => '',
            'rows' => self::plan($environment, self::answersOf($answers))['rows'],
            'buttons' => ['next' => _('Install'), 'back' => _('Back')],
            'state' => self::encode('review', $answers),
        ];
    }

    /**
     * What is wrong with the answers of the form, as a screen to show again: the form with the corrections made and the errors told,
     * or nothing when it is right.
     */
    private static function checkForm(Environment $environment, array $answers): ?array
    {
        $quick = self::plan($environment, self::answersOf($answers));
        $answers = array_replace($answers, $quick['corrected']);
        $fields = self::fields((string) ($answers['type'] ?? ''));
        $labels = array_map(static fn(array $field): string => $field[0], $fields) + array_map(static fn(array $o): string => $o['label'], self::options());
        $lines = [];
        foreach ($quick['problems'] as $question => $message) {
            $lines[] = sprintf('Error: %s %s', $labels[$question] ?? $question, $message);
        }
        if ((string) ($answers['password'] ?? '') !== (string) ($answers['password2'] ?? '')) {
            $lines[] = 'Error: Passwords differ';
            $answers['password'] = $answers['password2'] = '';
        }

        return $lines === [] ? null : self::screenForm($answers, implode("\n", $lines));
    }

    /** Make the folders and the files of the setup: the end of the scenario. */
    private static function install(Environment $environment, array $answers): array
    {
        $built = self::build($environment, self::answersOf($answers));
        if ($built['problems'] !== []) {
            return self::screenForm($answers, _('The answers are not right any more: look at them again.'));
        }
        $plan = $built['plan'];
        $simulator = $built['simulator'];
        if ($plan->binDir === '' || !is_dir($plan->binDir)) {
            return ['action' => 'abort', 'error' => sprintf(_('No OpenSim core found in %s.'), $environment->layout->coreRoot())];
        }
        // The place must be one the setup can write in: the nearest folder that exists
        $existing = $plan->gridDir;
        while ($existing !== dirname($existing) && !file_exists($existing)) {
            $existing = dirname($existing);
        }
        if (!is_dir($existing) || !is_writable($existing)) {
            return [
                'action' => 'abort',
                'error' => sprintf(
                    _('Cannot write in %s: run the setup as a user who can, or set EtcRoot in opensim.conf to a folder that can be written.'),
                    $existing,
                ),
            ];
        }
        // The files and the folders only: the databases, the account of the avatar and the start come after
        $plan->enable = $plan->start = $simulator->enable = $simulator->start = $simulator->createOwner = false;
        $ui = new NullUi();
        try {
            (new GridProvisioner($ui))->apply($plan, $environment);
            (new SimulatorProvisioner($ui))->apply($simulator, $environment);
        } catch (SetupFailedException $failed) {
            $errors = array_column(array_filter($ui->said, static fn(array $said): bool => $said[0] === 'error'), 1);

            return ['action' => 'abort', 'error' => implode("\n", $errors ?: [$failed->getMessage()])];
        }
        // Written is written: the files are there, or the setup says they are not
        $missing = array_values(array_filter(
            [(new GridConf())->path($plan), $plan->standalone ? '' : $plan->robustIni(), $simulator->iniPath()],
            static fn(string $file): bool => $file !== '' && !is_file($file),
        ));
        if ($missing !== []) {
            return ['action' => 'abort', 'error' => sprintf(_('These files were not written: %s'), implode(', ', $missing))];
        }
        $notes = array_column(array_filter($ui->said, static fn(array $said): bool => $said[0] === 'warn'), 1);

        return [
            'action' => 'quit',
            'message' => implode("\n", [
                sprintf('Grid name:  %s', $plan->gridName),
                sprintf('Login URI:  http://%s:%d', $plan->baseHostname, $plan->publicPort),
                sprintf('Avatar:     %s', $simulator->estateOwner),
                '',
                sprintf(_('Written in %s.'), $plan->gridDir),
                $plan->standalone
                    ? _('Not done yet: the database.')
                    : sprintf(_('Not done yet: the databases and the account of %s.'), $simulator->estateOwner),
                ...$notes,
            ]),
        ];
    }

    /** The questions of a type, by the name of the answer: the label and whether it is typed hidden. */
    private static function fields(string $type): array
    {
        return [
            'name' => [$type === 'standalone' ? _('Region name') : _('Grid name'), false],
            'login_uri' => [_('Login URI'), false],
            'avatar' => [_('Avatar name'), false],
            'password' => [_('Password'), true],
            'password2' => [_('Password again'), true],
            'email' => [_('Email'), false],
        ];
    }

    /** The answers a client gave, among the ones it may give, added to the ones kept. */
    private static function keep(array $answers, array $given, array $names, bool $flags = false): array
    {
        foreach ($names as $name) {
            if (array_key_exists($name, $given)) {
                $answers[$name] = $flags ? Result::isTrue($given[$name]) : (string) $given[$name];
            }
        }

        return $answers;
    }

    /** The answers the planning takes (the confirmation of the password is the scenario's). */
    private static function answersOf(array $answers): array
    {
        unset($answers['password2']);

        return $answers;
    }

    private static function notImplemented(Environment $environment): string
    {
        $done = array_column(array_filter(self::types($environment), static fn(array $type): bool => $type['implemented']), 'label');

        return sprintf(_('Not implemented yet: only %s for now.'), implode(' and ', $done));
    }

    /** The state a client holds between two steps: the step and the answers so far, as one string. */
    private static function encode(string $step, array $answers): string
    {
        return rtrim(strtr(base64_encode((string) json_encode(['step' => $step, 'answers' => $answers])), '+/', '-_'), '=');
    }

    /** @return array{step:string,answers:array<string,mixed>} the start for a state that is not one */
    private static function decode(string $state): array
    {
        $decoded = $state === '' ? null : json_decode((string) base64_decode(strtr($state, '-_', '+/')), true);

        return is_array($decoded) && is_string($decoded['step'] ?? null) && is_array($decoded['answers'] ?? null)
            ? ['step' => $decoded['step'], 'answers' => $decoded['answers']]
            : ['step' => '', 'answers' => []];
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
     * @param array<string,bool> $chosen the options, by name
     * @return list<array{section:string,label:string,value:string}>
     */
    private static function rows(GridPlan $plan, SimulatorPlan $simulator, bool $standalone, string $email, array $chosen): array
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
        $add('grid', 'Region spacing', $plan->regionSpacing === 0 ? 'side by side' : "{$plan->regionSpacing} free block(s)");
        $plan->coreDirectory !== '' && $add('grid', 'Core', $plan->coreDirectory);
        $standalone || $add('grid', 'Ports', "public {$plan->publicPort}, private {$plan->privatePort}");
        $standalone || $add('grid', 'Console', $console($plan->consoleMode, $plan->consolePort, $plan->consoleUser));
        $add('grid', 'Web URL', $plan->webUrl);
        $plan->helpers && $add('grid', 'Web server', ['caddy' => 'Caddy', 'apache' => 'Apache', 'nginx' => 'nginx'][$plan->webServer] ?? $plan->webServer);
        $add('grid', $standalone ? 'Database' : 'Grid database', $database($plan->dbName, $plan->dbHost, $plan->dbUser));
        $standalone || $add('grid', 'Robust config', $plan->robustIni());
        $add('grid', 'Grid folder', $plan->etcDirectory);

        // What the person chose, then what is on whatever the answers (the helpers and what they serve)
        $todo = static fn(string $name, string $value): string => $value . (self::options()[$name]['implemented'] ? '' : ' (not implemented)');
        $add('options', 'Hypergrid', $todo('hypergrid', $yes($plan->enableHypergrid)));
        $add('options', 'SSL', $todo('ssl', $yes($chosen['ssl'] ?? false)));
        $add('options', 'Cross-grid search', $todo('search', $plan->search === 'shared' ? 'yes, the shared provider (2do.directory)' : 'no, the search of this grid'));
        $add('options', 'Economy', $todo('gloebit', $plan->economy === 'gloebit' ? 'Gloebit' : 'BetaGridLikeMoneyModule'));
        $add('options', 'Extended OSSL', $todo('ossl', $yes($plan->ossl)));
        $add('options', 'Helpers', $plan->helpers ? "served at {$plan->webUrl}{$plan->helpersPath}" : 'not served by this web site');
        $add('options', 'OpenSimSearch module', $yes($plan->search !== 'disabled'));
        $add('options', 'Offline messages', ['helpers' => 'the helpers (module V1)', 'robust' => 'the grid (module V2)', 'both' => 'both'][$plan->offline] ?? $plan->offline);
        $add('options', 'Profiles', $yes($plan->helpers));
        $add('options', 'Destination guide', $yes($plan->helpers));
        $add('options', 'Grid status', $yes($plan->helpers));

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
