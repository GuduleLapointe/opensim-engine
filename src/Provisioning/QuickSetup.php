<?php

namespace OpenSim\Provisioning;

use OpenSim\Exceptions\SetupFailedException;
use OpenSim\Forms\FieldSet;
use OpenSim\Models\Grid;
use OpenSim\Result;
use OpenSim\Runtime\Environment;
use OpenSim\Runtime\ShellService;

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
    /** Setup operations clients can request explicitly, before sending a provisioning command. */
    public const CAPABILITIES = ['prepare', 'apply_prepared', 'existing_region'];

    /**
     * The options of the form, in the order they are shown. They are the only choices of a quick setup: everything else is on (the
     * search module, the offline messages, the profiles, the destination guide, the grid status). `plan` says what an option sets in
     * the plan of the grid, from its value and from all of them (nothing yet for SSL), `implemented` false that the setup does not
     * apply it yet.
     *
     * @return array<string,array{label:string,default:bool,implemented:bool,plan:?\Closure,uses?:list<string>}>
     */
    private static function options(Environment $environment): array
    {
        $options = [
            'ssl' => ['label' => 'SSL', 'default' => false, 'implemented' => false, 'plan' => null],
            'hypergrid' => [
                'label' => 'Hypergrid',
                'default' => true,
                'implemented' => true,
                'plan' => static fn(bool $on): array => ['hypergrid' => $on],
            ],
            'helpers' => [
                'label' => 'Helpers',
                'default' => true,
                'implemented' => true,
                'plan' => static fn(bool $on): array => ['helpers' => $on],
            ],
            'search' => [
                'label' => 'Cross-grid Search',
                'default' => true,
                'implemented' => true,
                // The search of the grid is its helpers', else there is none
                'plan' => static fn(bool $on, array $chosen): array => [
                    'search' => $on ? 'shared' : ($chosen['helpers'] ? 'internal' : 'disabled'),
                ],
            ],
        ];
        // The others are presets that say they are offered (see OptionTemplate): the administrator adds one by adding a folder
        foreach ((new Presets([], $environment->layout->etcRoot()))->options() as $name => $template) {
            if ($template->offered() && !isset($options[$name])) {
                $options[$name] = [
                    'label' => $template->label(),
                    'default' => $template->default(),
                    'implemented' => true,
                    'plan' => null,
                    'uses' => $template->uses(),
                ];
            }
        }

        return $options;
    }

    /**
     * What can be set up: the grid and the standalone, a simulator that joins another grid, and the presets (the ones planned stay in
     * the list, the ones this machine has can be set up). The kind says how: a grid, a standalone, a simulator that joins a grid
     * given by its address (remote), or one that joins the grid of a preset.
     *
     * @return list<array{name:string,label:string,implemented:bool,kind:string}>
     */
    private static function types(Environment $environment): array
    {
        $types = [
            ['name' => 'grid', 'label' => 'Grid', 'implemented' => true, 'kind' => 'grid'],
            ['name' => 'standalone', 'label' => 'Standalone', 'implemented' => true, 'kind' => 'standalone'],
            [
                'name' => 'simulator',
                'label' => 'Simulator (join other grid)',
                'implemented' => true,
                'kind' => 'remote',
            ],
        ];
        $presets = new Presets([], $environment->layout->etcRoot());
        $found = $presets->names();
        foreach (['speculoos' => 'Speculoos', 'osgrid' => 'OSGrid'] + $found as $name => $grid) {
            $types[] = [
                'name' => (string) $name,
                'label' => $grid,
                'implemented' => isset($found[$name]) && $presets->describe((string) $name) !== null,
                'kind' => 'preset',
            ];
        }

        return $types;
    }

    /** How a type is set up (grid, standalone, remote, preset), empty for a type that is not one. */
    private static function kindOf(Environment $environment, string $type): string
    {
        return array_column(self::types($environment), 'kind', 'name')[$type] ?? '';
    }

    /**
     * The test doubles of the work that needs a machine: what a grid says of itself (Closure(string $host, int $port): array).
     */
    private static ?\Closure $says = null;

    /** The questions the planners' fields answer, by the name of the field. */
    private const QUESTIONS = [
        'name' => 'name',
        'hostname' => 'login_uri',
        'public_port' => 'login_uri',
        'new_name' => 'avatar',
        'new_password' => 'password',
        'new_email' => 'email',
        'estate_owner' => 'avatar',
    ];

    /**
     * @param array<string,mixed> $answers type, name, hostname (destination proposal), login_uri, avatar, password, email and the options (ssl, hypergrid, search,
     *        gloebit, ossl): all optional, a missing one is proposed or, when it cannot be, told in the problems
     * @return array{valid:bool,problems:array<string,string>,corrected:array<string,string>,identity:string,setup:array<string,mixed>,rows:list<array{section:string,label:string,value:string}>,types:list<array{name:string,label:string,implemented:bool}>,options:list<array{name:string,label:string,default:bool,value:bool,implemented:bool}>}
     */
    public static function plan(Environment $environment, array $answers): array
    {
        if (
            in_array(
                self::kindOf($environment, strtolower(trim((string) ($answers['type'] ?? '')))),
                ['remote', 'preset'],
                true,
            )
        ) {
            return self::planRemote($environment, $answers);
        }
        $built = self::build($environment, $answers);

        return [
            'valid' => $built['problems'] === [],
            'problems' => $built['problems'],
            'corrected' => $built['corrected'],
            'identity' => $built['standalone'] ? $built['simulator']->slug : $built['plan']->gridNick,
            'setup' => SetupFile::withSimulator(SetupFile::withGrid([], $built['plan']), $built['simulator']),
            'rows' => self::rows(
                $built['plan'],
                $built['simulator'],
                $built['standalone'],
                trim((string) ($answers['email'] ?? '')),
                $built['chosen'],
                $built['labels'],
            ),
            'types' => self::publicTypes($built['types']),
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

    /** The types as the API tells them: the kind is the business of the scenario. */
    private static function publicTypes(array $types): array
    {
        return array_map(static fn(array $type): array => array_diff_key($type, ['kind' => 1]), $types);
    }

    /**
     * The plans the answers stand for, and what is wrong with them.
     *
     * @param array<string,mixed> $answers
     * @return array{problems:array<string,string>,corrected:array<string,string>,plan:GridPlan,simulator:SimulatorPlan,standalone:bool,chosen:array<string,bool>,types:list<array{name:string,label:string,implemented:bool,kind:string}>,options:array<string,array{label:string,default:bool,implemented:bool,plan:?\Closure}>}
     */
    private static function build(Environment $environment, array $answers): array
    {
        $problems = [];
        $corrected = [];
        $type = strtolower(trim((string) ($answers['type'] ?? '')));
        $types = self::types($environment);
        if ($type !== '' && !in_array($type, self::TYPES, true)) {
            $problems['type'] = in_array($type, array_column($types, 'name'), true)
                ? _('not implemented')
                : _('invalid');
        }
        $standalone = $type === 'standalone';
        if (trim((string) ($answers['existing_region'] ?? ($answers['setup']['simulators'][0]['regions'][0]['source'] ?? ''))) !== '') {
            $problems['existing_region'] = _('Use a simulator setup to adopt a region of an existing grid.');
        }

        // The options: what is chosen, and what each sets in the plan of the grid
        $options = self::options($environment);
        foreach (
            (new FieldSet(
                array_map(
                    static fn(string $name): array => ['name' => $name, 'type' => 'boolean'],
                    array_keys($options),
                ),
            ))->problems($answers)
            as $name => $message
        ) {
            $problems[$name] = $message;
        }
        $chosen = [];
        foreach ($options as $name => $option) {
            $given = $answers[$name] ?? null;
            $chosen[$name] = Result::isTrue($given) || (Result::isFalse($given) ? false : $option['default']);
        }
        $fromOptions = [];
        foreach ($options as $name => $option) {
            if ($option['plan'] === null) {
                // A preset: it is on or off by name, and what needs the helpers cannot be on without them
                $fromOptions['options'][$name] = $chosen[$name];
                $chosen[$name] &&
                    !$chosen['helpers'] &&
                    in_array('helpers', $option['uses'] ?? [], true) &&
                    ($problems[$name] = _('needs Helpers'));

                continue;
            }
            $fromOptions = $option['plan']($chosen[$name], $chosen) + $fromOptions;
        }

        // The grid. What is right or wrong, and what is corrected, is said by the fields of the models: the answers go to them as given,
        // and the plan is made of the corrected ones (the person confirms a correction by sending the form again).
        $dbGiven = array_intersect_key(
            $answers,
            array_flip(['database_host', 'database_name', 'database_user', 'database_password']),
        );
        $gridAnswers = ['standalone' => $standalone] + self::portValues($answers, ['private_port' => 'private_port', 'console_port' => 'grid_console_port']) + $dbGiven + $fromOptions;
        trim((string) ($answers['name'] ?? '')) !== '' && ($gridAnswers['name'] = trim((string) $answers['name']));
        [$uri, $uriProblem] = Grid::parseLoginUri((string) ($answers['login_uri'] ?? ''));
        $uriProblem !== null && ($problems['login_uri'] = $uriProblem);
        $gridAnswers += $uri;
        if (!isset($gridAnswers['hostname']) && trim((string) ($answers['hostname'] ?? '')) !== '') {
            $gridAnswers['hostname'] = (string) $answers['hostname'];
        }
        if (is_array($answers['setup'] ?? null)) {
            $gridAnswers = array_replace($gridAnswers, SetupFile::gridValues($answers['setup']));
        }
        $planner = GridPlanner::forMachine($environment);
        $fixes = self::review(
            $planner->fields(),
            $planner->problems($gridAnswers),
            $gridAnswers,
            $problems,
            $corrected,
            ['console_port' => 'grid_console_port'],
        );
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
        $owner =
            [
                'estate_owner' => '+',
                'new_name' => trim((string) ($answers['avatar'] ?? '')),
                'new_password' => (string) ($answers['password'] ?? ''),
                'new_email' => trim((string) ($answers['email'] ?? '')),
            ] + array_diff_key($dbGiven, ['database_name' => 1]);
        $owner += self::portValues($answers, ['http_port' => 'http_port', 'console_port' => 'console_port']);
        if (is_array($answers['setup'] ?? null)) {
            $owner = array_replace($owner, SetupFile::simulatorValues($answers['setup'], 0));
        }
        $fixes = self::review($simulators->fields(), $simulators->problems($owner), $owner, $problems, $corrected);
        $simulator = $simulators->plan($fixes + $owner);
        if ($standalone && $simulator->httpPort !== $plan->publicPort) {
            $problems['http_port'] = _('must match the standalone Login URI port');
        }
        $regions = RegionPlanner::forSimulator($simulator, $grid, $database, defaultName: $region);
        $regionAnswers = is_array($answers['setup'] ?? null) ? SetupFile::regionValues($answers['setup'], 0, 0) : ['name' => $region];
        $regionAnswers += self::portValues($answers, ['port' => 'region_port']);
        $taken ||
            self::review(
                $regions->fields(),
                $regions->problems($regionAnswers),
                $regionAnswers,
                $problems,
                $corrected,
                ['port' => 'region_port'],
            );
        $regions->apply($simulator, $regionAnswers);

        if ($environment->occupiedPorts !== null) {
            $selected = [
                'login_uri' => $plan->publicPort,
                'private_port' => $plan->privatePort,
                'grid_console_port' => $plan->consolePort,
                'http_port' => $simulator->httpPort,
                'console_port' => $simulator->consolePort,
                'region_port' => $simulator->regionPort,
            ];
            foreach ($selected as $field => $port) {
                if (in_array($port, $environment->occupiedPorts, true)) {
                    $problems[$field] = _('already in use on the selected host');
                }
            }
        }

        return [
            'problems' => $problems,
            'corrected' => $corrected,
            'plan' => $plan,
            'simulator' => $simulator,
            'standalone' => $standalone,
            'chosen' => $chosen,
            'labels' => array_map(
                static fn(OptionTemplate $t): string => $t->label(),
                array_filter(
                    (new Presets([], $environment->layout->etcRoot()))->options(),
                    static fn(OptionTemplate $t): bool => $t->shown(),
                ),
            ),
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
     * @param array{state?:string,button?:string,answers?:array<string,mixed>,mode?:string,occupied_ports?:list<int>} $request mode: apply (default) or prepare
     * @return array<string,mixed> screen, quit, abort or prepared (configuration, including secret answers)
     */
    public static function next(Environment $environment, array $request): array
    {
        if (array_key_exists('occupied_ports', $request)) {
            if (!is_array($request['occupied_ports']) || !array_is_list($request['occupied_ports'])) {
                throw new \InvalidArgumentException('Occupied ports must be a list');
            }
            $environment = $environment->onHostWithOccupiedPorts($request['occupied_ports']);
        }
        $mode = $request['mode'] ?? 'apply';
        if (!in_array($mode, ['apply', 'prepare'], true)) {
            throw new \InvalidArgumentException('Unknown setup mode');
        }
        if (array_key_exists('configuration', $request)) {
            if ($mode !== 'apply' || !is_array($request['configuration'])) {
                throw new \InvalidArgumentException('A prepared configuration requires apply mode');
            }
            return self::applyConfiguration($environment, $request['configuration']);
        }
        $prepare = $mode === 'prepare';
        $result = self::advance($environment, $request, $prepare);
        if ($prepare) {
            $result['mode'] = 'prepare';
        }
        if ($prepare && in_array($result['kind'] ?? '', ['review', 'form'], true)) {
            if (($result['kind'] ?? '') === 'review') {
                $result['buttons']['next'] = _('Continue');
                $result['wait'] = '';
            } elseif (($result['title'] ?? '') === _('Database')) {
                $result['buttons']['next'] = _('Save configuration');
                $result['wait'] = '';
            }
        }
        return $result;
    }

    /** Advance the questionnaire, optionally ending with collected answers instead of provisioning. */
    private static function advance(Environment $environment, array $request, bool $prepare): array
    {
        $state = self::decode((string) ($request['state'] ?? ''));
        $button = in_array($request['button'] ?? 'next', ['next', 'back', 'quit'], true)
            ? $request['button'] ?? 'next'
            : 'next';
        $answers = $state['answers'];
        $given = is_array($request['answers'] ?? null) ? $request['answers'] : [];
        if ($state['step'] === '') {
            return self::screenType($environment, $answers, '');
        }
        if ($button === 'quit') {
            return ['action' => 'abort', 'error' => _('Cancelled')];
        }
        $kind = self::kindOf($environment, (string) ($answers['type'] ?? ''));
        $remote = in_array($kind, ['remote', 'preset'], true);

        switch ($state['step']) {
            case 'type':
                if ($button === 'back') {
                    return ['action' => 'abort', 'error' => _('Cancelled')];
                }
                $type = strtolower(trim((string) ($given['type'] ?? ($answers['type'] ?? ''))));
                $answers['type'] = $type;
                $answers = array_replace($answers, array_intersect_key($given, array_flip(['hostname', 'login_uri'])));
                if (isset($given['existing_region'])) {
                    $answers['existing_region'] = (string) $given['existing_region'];
                    if ($answers['existing_region'] !== '') {
                        try {
                            $existing = RegionAdoption::describe($answers['existing_region']);
                            $answers += ['name' => $existing['name']];
                        } catch (\InvalidArgumentException $error) {
                            return ['action' => 'abort', 'error' => $error->getMessage()];
                        }
                    }
                }
                $known = array_column(self::types($environment), 'implemented', 'name');
                if (($known[$type] ?? false) !== true) {
                    return self::screenType($environment, $answers, self::notImplemented($environment));
                }
                if (in_array(self::kindOf($environment, $type), ['remote', 'preset'], true)) {
                    $answers += ['name' => 'Welcome'];
                } else {
                    // The proposals of the engine are what the form starts with
                    $proposed = self::plan($environment, $answers)['setup']['grid'];
                    $answers += [
                        'name' => $proposed['name'],
                        'login_uri' => $proposed['hostname'] . ':' . $proposed['public_port'],
                    ];
                }

                return self::screenForm($environment, $answers, '');

            case 'form':
                $answers = self::keep($answers, $given, self::editable(self::fields($environment, $answers)));
                if ($button === 'back') {
                    return self::screenType($environment, $answers, '');
                }
                if (($screen = self::checkForm($environment, $answers)) !== null) {
                    return $screen;
                }

                // What a simulator joins is the grid's: there are no options to choose
                return $remote
                    ? self::screenReview($environment, self::pinned($environment, $answers))
                    : self::screenOptions($environment, $answers, '');

            case 'options':
                $options = self::options($environment);
                $answers = self::keep($answers, $given, array_keys($options), true);
                if ($button === 'back') {
                    return self::screenForm($environment, $answers, '');
                }
                // What is served by the helpers puts them on, and the person is told
                foreach ($options as $name => $option) {
                    if (
                        Result::isTrue($answers[$name] ?? $option['default']) &&
                        in_array('helpers', $option['uses'] ?? [], true) &&
                        !Result::isTrue($answers['helpers'] ?? true)
                    ) {
                        $answers['helpers'] = true;

                        return self::screenOptions(
                            $environment,
                            $answers,
                            sprintf(_('%s needs the helpers: Helpers is on.'), $option['label']),
                        );
                    }
                }
                if (($screen = self::checkForm($environment, $answers)) !== null) {
                    return $screen;
                }

                return self::screenReview($environment, self::pinned($environment, $answers));

            case 'database':
                $answers = self::keep($answers, $given, array_keys(self::databaseFields()));
                if ($button === 'back') {
                    return self::screenReview($environment, $answers);
                }

                return $prepare ? self::prepared($environment, $answers) : self::install($environment, $answers);

            case 'review':
                if ($button === 'back') {
                    return $remote
                        ? self::screenForm($environment, $answers, '')
                        : self::screenOptions($environment, $answers, '');
                }

                $answers = self::keep($answers, $given, [
                    'database_host',
                    'database_user',
                    'database_password',
                    'admin_user',
                    'admin_password',
                ]);

                if ($prepare) {
                    $plan = $remote
                        ? self::buildRemote($environment, self::answersOf($answers))['simulator']->databasePlan()
                        : self::build($environment, self::answersOf($answers))['plan'];
                    return self::screenDatabase($plan, $answers, '');
                }
                return self::install($environment, $answers);
        }

        return self::screenType($environment, [], '');
    }

    /** The collected answers and their preview; no database, account or file is created. */
    private static function prepared(Environment $environment, array $answers): array
    {
        $answers = self::pinned($environment, $answers);
        $planned = self::plan($environment, self::answersOf($answers));
        if (!$planned['valid']) {
            return self::screenForm($environment, $answers, implode("\n", $planned['problems']));
        }
        return [
            'action' => 'prepared',
            'configuration' => ['version' => 1, 'identity' => $planned['identity'], 'answers' => $answers, 'setup' => $planned['setup']],
            'message' => _('Setup configuration prepared.'),
        ];
    }

    /** Apply a confirmed, versioned configuration without repeating the questionnaire. */
    private static function applyConfiguration(Environment $environment, array $configuration): array
    {
        if (($configuration['version'] ?? null) !== 1 || !is_array($configuration['answers'] ?? null) || !is_array($configuration['setup'] ?? null)) {
            return ['action' => 'abort', 'error' => _('Invalid prepared setup configuration.')];
        }
        try {
            $setup = SetupFile::parse(json_encode($configuration['setup'], JSON_THROW_ON_ERROR));
        } catch (\InvalidArgumentException | \JsonException $error) {
            return ['action' => 'abort', 'error' => $error->getMessage()];
        }
        $answers = $configuration['answers'];
        $answers['setup'] = $setup;
        $planned = self::plan($environment, self::answersOf($answers));
        if (!$planned['valid']) {
            $problems = [];
            foreach ($planned['problems'] as $field => $message) {
                $problems[] = "$field: $message";
            }
            return ['action' => 'abort', 'error' => implode("\n", $problems)];
        }
        foreach (['grid', 'simulator', 'region'] as $section) {
            $given = match ($section) {
                'grid' => SetupFile::gridValues($setup),
                'simulator' => SetupFile::simulatorValues($setup, 0),
                'region' => SetupFile::regionValues($setup, 0, 0),
            };
            $effective = match ($section) {
                'grid' => SetupFile::gridValues($planned['setup']),
                'simulator' => SetupFile::simulatorValues($planned['setup'], 0),
                'region' => SetupFile::regionValues($planned['setup'], 0, 0),
            };
            foreach ($given as $field => $value) {
                if ($value !== ($effective[$field] ?? null)) {
                    return ['action' => 'abort', 'error' => sprintf(_('The prepared setting %s changed on the target; prepare the setup again.'), "$section.$field")];
                }
            }
        }
        if ($planned['identity'] !== ($configuration['identity'] ?? null)) {
            return ['action' => 'abort', 'error' => _('The prepared instance identity does not match its settings.')];
        }
        $result = self::install($environment, $answers);
        if (($result['action'] ?? '') === 'screen') {
            return ['action' => 'abort', 'error' => (string) ($result['notice'] ?? _('The prepared setup could not be applied.'))];
        }
        return $result;
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
    private static function screenForm(Environment $environment, array $answers, string $notice): array
    {
        $type = (string) ($answers['type'] ?? '');
        $setup = self::plan($environment, self::answersOf($answers))['setup'];
        $grid = $setup['grid'] ?? [];
        $simulator = $setup['simulators'][0] ?? [];
        $proposals = [
            'private_port' => $grid['private_port'] ?? null,
            'grid_console_port' => $grid['console_port'] ?? null,
            'http_port' => $simulator['http_port'] ?? null,
            'console_port' => $simulator['console_port'] ?? null,
            'region_port' => $simulator['regions'][0]['port'] ?? null,
        ];
        $fields = [];
        foreach (self::fields($environment, $answers) as $name => [$label, $secret, $readonly, $fixed]) {
            $value = $answers[$name] ?? $fixed ?? '';
            if (trim((string) $value) === '' && isset($proposals[$name])) {
                $value = $proposals[$name];
            }
            $fields[] = [
                'name' => $name,
                'label' => $label,
                'value' => $readonly ? ($fixed ?? '') : (string) $value,
                'secret' => $secret,
                'readonly' => $readonly,
            ];
        }
        $labels = array_column(self::types($environment), 'label', 'name');

        return [
            'action' => 'screen',
            'kind' => 'form',
            'title' => $labels[$type] ?? _('Grid'),
            'text' => '',
            'notice' => $notice,
            'fields' => $fields,
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'state' => self::encode('form', $answers),
        ];
    }

    /** The options, each with its proposal or the last choice. */
    private static function screenOptions(Environment $environment, array $answers, string $notice): array
    {
        $items = [];
        foreach (self::options($environment) as $name => $option) {
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
        $quick = self::plan($environment, self::answersOf($answers));

        return [
            'action' => 'screen',
            'kind' => 'review',
            'title' => _('Review'),
            'text' => implode("\n", $quick['warnings'] ?? []),
            'notice' => '',
            'rows' => $quick['rows'],
            'buttons' => ['next' => ($answers['existing_region'] ?? '') !== '' ? _('Stage import') : _('Install'), 'back' => _('Back')],
            'wait' => _('Installing, this can take a few minutes…'),
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
        $fields = self::fields($environment, $answers);
        $labels =
            array_map(static fn(array $field): string => $field[0], $fields) +
            array_map(static fn(array $o): string => $o['label'], self::options($environment));
        $lines = [];
        foreach ($quick['problems'] as $question => $message) {
            $lines[] = sprintf('Error: %s %s', $labels[$question] ?? $question, $message);
        }
        if ((string) ($answers['password'] ?? '') !== (string) ($answers['password2'] ?? '')) {
            $lines[] = 'Error: Passwords differ';
            $answers['password'] = $answers['password2'] = '';
        }

        return $lines === [] ? null : self::screenForm($environment, $answers, implode("\n", $lines));
    }

    /**
     * What replaces the work that needs a machine, for the tests: how the databases are checked and made (Closure(GridPlan, UiInterface):
     * int, a DatabaseService code), how the account of the avatar is made (Closure(Grid, SimulatorPlan, UiInterface): bool) and what a
     * grid says of itself (Closure(string $host, int $port): array<string,string>), and what the planner of a region of a grid on
     * another machine asks it (the context of RegionPlanner: remoteHas, freePlace, registeredUuid, registeredName).
     *
     * @param array{database?:?\Closure,account?:?\Closure,says?:?\Closure,region?:array<string,mixed>} $doubles none: the real work
     */
    public static function through(array $doubles = []): void
    {
        self::$database = $doubles['database'] ?? null;
        self::$account = $doubles['account'] ?? null;
        self::$says = $doubles['says'] ?? null;
        self::$region = $doubles['region'] ?? [];
    }

    /** @var array<string,mixed> */
    private static array $region = [];

    private static ?\Closure $database = null;
    private static ?\Closure $account = null;

    /**
     * Make the setup: the databases are checked and made, the folders and the files are written, a grid is started, the account of the
     * avatar is made, the simulator is written and started. The end of the scenario.
     */
    private static function install(Environment $environment, array $answers): array
    {
        $answers = self::pinned($environment, $answers);
        $remote = in_array(self::kindOf($environment, (string) ($answers['type'] ?? '')), ['remote', 'preset'], true);
        if ($remote) {
            $built = self::buildRemote($environment, self::answersOf($answers));
            $plan = null;
            $grid = $built['grid'];
        } else {
            $built = self::build($environment, self::answersOf($answers));
            $plan = $built['plan'];
            $grid = null;
        }
        $simulator = $built['simulator'];
        if ($built['problems'] !== [] || $simulator === null) {
            return self::screenForm(
                $environment,
                $answers,
                _('The answers are not right any more: look at them again.'),
            );
        }
        $gridDir = $plan?->gridDir ?? $simulator->gridDir;
        $binDir = $plan?->binDir ?? $simulator->binDir();
        if ($binDir === '' || !is_dir($binDir)) {
            return [
                'action' => 'abort',
                'error' => sprintf(_('No OpenSim core found in %s.'), $environment->layout->coreRoot()),
            ];
        }
        // The place must be one the setup can write in: the nearest folder that exists
        $existing = $gridDir;
        while ($existing !== dirname($existing) && !file_exists($existing)) {
            $existing = dirname($existing);
        }
        // As root, a machine that has no system layout gets it: the account, the folders, opensim.conf
        if ((!is_dir($existing) || !is_writable($existing)) && SystemLayout::needed($environment->layout)) {
            (new SystemLayout(new NullUi(live: true)))->prepare($environment->layout);
            $existing = $gridDir;
            while ($existing !== dirname($existing) && !file_exists($existing)) {
                $existing = dirname($existing);
            }
        }
        if (!is_dir($existing) || !is_writable($existing)) {
            return [
                'action' => 'abort',
                'error' => sprintf(
                    _(
                        'Cannot write in %s: run the setup as a user who can, or set EtcRoot in opensim.conf to a folder that can be written.',
                    ),
                    $existing,
                ),
            ];
        }

        // The notes are told as they come: the install takes minutes, a client shows its progress from the error output
        $ui = new NullUi(live: true);
        // Nothing is written before the databases work: a problem of access is asked again, with the reasons
        $databases = [];
        if ($simulator->existingRegionConfig === '') {
            $databases = $plan === null ? [$simulator->databasePlan()] : ($plan->standalone ? [$plan] : [$plan, $simulator->databasePlan()]);
            if (isset($answers['admin_user'])) {
                DatabaseService::rememberAdmin($databases[0]->dbHost, (string) $answers['admin_user'], (string) ($answers['admin_password'] ?? ''));
            }
            DatabaseService::remember($databases[0]->dbHost, $databases[0]->dbUser, $databases[0]->dbPass);
        }
        $check =
            self::$database ??
            static fn(GridPlan $db, UiInterface $said): int => (new DatabaseService($said))->ensure($db);
        foreach ($databases as $db) {
            $code = $check($db, $ui);
            if ($code === DatabaseService::OK) {
                continue;
            }
            $said = implode("\n", array_unique(array_column($ui->said, 1)));
            if ($code === DatabaseService::ABORT) {
                return [
                    'action' => 'abort',
                    'error' =>
                        $said . "\n" . _('Stopped, nothing was changed: OpenSim cannot run without its database.'),
                ];
            }

            return self::screenDatabase($db, $answers, $said);
        }

        // What the commands say (a log, the owners) is kept, never printed: the answer of the API is a document
        ShellService::showOutputWith(static function (string $chunk) use ($ui): void {
            trim($chunk) !== '' && $ui->note(trim($chunk));
        });
        try {
            if ($plan !== null) {
                $plan->enable = $plan->start = true;
                (new GridProvisioner($ui))->apply($plan, $environment);
                // The account of the avatar: Robust is up, and makes it. A standalone makes it itself when it starts
                if (!$plan->standalone) {
                    $found = Grid::find($environment, $plan->gridNick);
                    $made =
                        self::$account ??
                        static fn(Grid $grid, SimulatorPlan $sim, UiInterface $said): bool => (new GridAccounts(
                            new DatabaseService($said),
                            $said,
                        ))->create($grid, $sim->estateOwner, $sim->ownerPassword, $sim->ownerEmail);
                    if ($found === null || !$made($found, $simulator, $ui)) {
                        throw new SetupFailedException('account');
                    }
                }
            }
            if ($simulator->existingRegionConfig === '') {
                $simulator->enable = $simulator->start = true;
            }
            $simulator->createOwner = false;
            (new SimulatorProvisioner($ui))->apply($simulator, $environment);
        } catch (SetupFailedException $failed) {
            $errors = array_column(array_filter($ui->said, static fn(array $said): bool => $said[0] === 'error'), 1);
            // The log of what did not start, when the setup showed it
            $at = array_search('Recent log:', array_column($ui->said, 1), true);
            $log = $at === false ? [] : array_column(array_slice($ui->said, $at + 1), 1);

            return ['action' => 'abort', 'error' => implode("\n", [...$errors ?: [$failed->getMessage()], ...$log])];
        } finally {
            ShellService::showOutputWith(null);
        }
        // Written is written: the files are there, or the setup says they are not
        $missing = array_values(
            array_filter(
                [
                    $plan !== null ? (new GridConf())->path($plan) : '',
                    $plan !== null && !$plan->standalone ? $plan->robustIni() : '',
                    $simulator->iniPath(),
                ],
                static fn(string $file): bool => $file !== '' && !is_file($file),
            ),
        );
        if ($missing !== []) {
            return [
                'action' => 'abort',
                'error' => sprintf(_('These files were not written: %s'), implode(', ', $missing)),
            ];
        }
        $notes = array_column(array_filter($ui->said, static fn(array $said): bool => $said[0] === 'warn'), 1);
        if ($simulator->existingRegionConfig !== '') {
            return [
                'action' => 'quit',
                'message' => sprintf(_('Simulator %s imported and left disabled. Restore its database and files, stop the source, then enable and start it.'), $simulator->slug),
            ];
        }

        return [
            'action' => 'quit',
            'message' => implode("\n", [
                sprintf('Grid name:  %s', $plan?->gridName ?? $grid?->name()),
                sprintf(
                    'Login URI:  http://%s:%d',
                    $plan?->baseHostname ?? $grid?->baseHostname(),
                    $plan?->publicPort ?? $grid?->publicPort(),
                ),
                sprintf('Avatar:     %s', $simulator->estateOwner),
                '',
                sprintf(_('Written in %s.'), $gridDir),
                $plan === null ? _('The simulator is running.') : _('The grid and its simulator are running.'),
                ...$notes,
                ...$ui->report === [] ? [] : ['', ...$ui->report],
            ]),
        ];
    }

    /** The access to the database, asked again: what the server said, and the settings with the account of its administrator. */
    private static function screenDatabase(GridPlan $plan, array $answers, string $notice): array
    {
        $values = $answers + [
            'database_host' => $plan->dbHost,
            'database_name' => $plan->dbName,
            'database_user' => $plan->dbUser,
            'database_password' => $plan->dbPass,
        ];
        $fields = [];
        foreach (self::databaseFields() as $name => [$label, $secret]) {
            $fields[] = [
                'name' => $name,
                'label' => $label,
                'value' => (string) ($values[$name] ?? ''),
                'secret' => $secret,
            ];
        }

        return [
            'action' => 'screen',
            'kind' => 'form',
            'title' => _('Database'),
            'text' => _('The administrator of the server is only needed to make the database or the user.'),
            'notice' => $notice,
            'fields' => $fields,
            'buttons' => ['next' => _('OK'), 'back' => _('Back')],
            'wait' => _('Checking the database, then installing: this can take a few minutes…'),
            'state' => self::encode('database', $answers),
        ];
    }

    /** The settings of the database, by the name of the answer: the label and whether it is typed hidden. */
    private static function databaseFields(): array
    {
        return [
            'database_host' => [_('Database host'), false],
            'database_name' => [_('Database name'), false],
            'database_user' => [_('Database user'), false],
            'database_password' => [_('Database password'), true],
            'admin_user' => [_('Administrator'), false],
            'admin_password' => [_('Administrator password'), true],
        ];
    }

    /**
     * The password of the database account is made at random, once: the answers keep it, so that the review and the setup agree
     * (nothing is kept between two steps but what the client sends back).
     */
    private static function pinned(Environment $environment, array $answers): array
    {
        if (!isset($answers['database_password'])) {
            $remote = in_array(
                self::kindOf($environment, (string) ($answers['type'] ?? '')),
                ['remote', 'preset'],
                true,
            );
            $answers['database_password'] = $remote
                ? self::buildRemote($environment, self::answersOf($answers))['simulator']?->dbPass ?? ''
                : self::build($environment, self::answersOf($answers))['plan']->dbPass;
            $answers['database_password'] === '' && ($answers['database_password'] = bin2hex(random_bytes(8)));
        }

        return $answers;
    }

    /**
     * The questions of the type chosen, by the name of the answer: the label, whether it is typed hidden, whether it is only shown and
     * the value it is shown with (when it is not an answer).
     *
     * @return array<string,array{0:string,1:bool,2:bool,3:?string}>
     */
    private static function fields(Environment $environment, array $answers): array
    {
        $type = (string) ($answers['type'] ?? '');
        $kind = self::kindOf($environment, $type);
        $avatar_name = getenv('DEFAULT_OWNER_NAME') ?: null;
        if (empty($avatar_name)) {
            $avatar_name = trim(
                sprintf(
                    '%s %s',
                    ucfirst(strtok(gethostname(), '.') ?: ucfirst($kind)),
                    in_array($kind, ['preset', 'remote']) ? _('Resident') : _('God'),
                ),
            );
        }
        $avatar_password = getenv('DEFAULT_OWNER_PASSWORD') ?: null;
        $region_name = sprintf(_("%s's Place"), strtok($avatar_name, ' '));
        $adoptionFields = trim((string) ($answers['existing_region'] ?? '')) !== ''
            ? ['database_name' => [_('Existing database'), false, false, null]]
            : [];
        if ($kind === 'preset') {
            $said = (new Presets([], $environment->layout->etcRoot()))->describe($type) ?? [];

            return [
                'preset_grid' => [_('Grid'), false, true, (string) ($said['name'] ?? $type)],
                'preset_login' => [
                    _('Login URI'),
                    false,
                    true,
                    ($said['host'] ?? '') . ':' . ($said['publicPort'] ?? ''),
                ],
                'preset_hypergrid' => [_('Hypergrid'), false, true, $said['hypergrid'] ?? true ? 'yes' : 'no'],
                'name' => [$adoptionFields !== [] ? _('Simulator name') : _('Region name'), false, false, $region_name],
                'avatar' => [_('Avatar name'), false, false, $avatar_name],
            ] + $adoptionFields + self::portFields($adoptionFields !== []);
        }
        if ($kind === 'remote') {
            return [
                'login_uri' => [_('Login URI of the grid'), false, false, null],
                'private_port' => [_('Grid private port'), false, false, null],
                'name' => [$adoptionFields !== [] ? _('Simulator name') : _('Region name'), false, false, $region_name],
                'avatar' => [_('Avatar name'), false, false, $avatar_name],
            ] + $adoptionFields + self::portFields($adoptionFields !== []);
        }

        return [
            'name' => [$kind === 'standalone' ? _('Region name') : _('Grid name'), false, false, null],
            'login_uri' => [_('Login URI'), false, false, null],
            'avatar' => [_('Avatar name'), false, false, $avatar_name],
            'password' => [_('Password'), true, false, $avatar_password],
            'password2' => [_('Password again'), true, false, $avatar_password],
            'email' => [_('Email'), false, false, null],
        ] + ($kind === 'standalone' ? [] : [
            'private_port' => [_('Grid private port'), false, false, null],
            'grid_console_port' => [_('Grid console port'), false, false, null],
        ]) + self::portFields(standalone: $kind === 'standalone');
    }

    /** Optional listener overrides; an empty answer keeps the planner's proposal. */
    private static function portFields(bool $existingRegion = false, bool $standalone = false): array
    {
        $fields = [
            'http_port' => [_('Simulator HTTP port'), false, false, null],
            'console_port' => [_('Simulator console port'), false, false, null],
        ];
        if ($standalone) {
            unset($fields['http_port']);
        }
        if (!$existingRegion) {
            $fields['region_port'] = [_('Region UDP port'), false, false, null];
        }
        return $fields;
    }

    /** Nonempty port answers, using the field names of the corresponding planner. */
    private static function portValues(array $answers, array $fields): array
    {
        $values = [];
        foreach ($fields as $field => $question) {
            if (trim((string) ($answers[$question] ?? '')) !== '') {
                $values[$field] = $answers[$question];
            }
        }
        return $values;
    }

    /**
     * The names of the questions a person answers (not the ones that are only shown).
     *
     * @return list<string>
     */
    private static function editable(array $fields): array
    {
        return array_keys(array_filter($fields, static fn(array $field): bool => !$field[2]));
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
        $done = array_column(
            array_filter(self::types($environment), static fn(array $type): bool => $type['implemented']),
            'label',
        );

        return sprintf(_('Not implemented yet: only %s for now.'), implode(', ', $done));
    }

    /** The state a client holds between two steps: the step and the answers so far, as one string. */
    private static function encode(string $step, array $answers): string
    {
        return rtrim(
            strtr(base64_encode((string) json_encode(['step' => $step, 'answers' => $answers])), '+/', '-_'),
            '=',
        );
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
     * The plan of a simulator that joins a grid, given by its address or by a preset: the reply of plan() for it. The grid is not made
     * here, so there are no options.
     */
    private static function planRemote(Environment $environment, array $answers): array
    {
        $built = self::buildRemote($environment, $answers);
        $simulator = $built['simulator'];

        return [
            'valid' => $built['problems'] === [],
            'problems' => $built['problems'],
            'corrected' => $built['corrected'],
            'identity' => $simulator?->slug ?? '',
            'setup' => $simulator !== null ? SetupFile::withSimulator([], $simulator) : [],
            'rows' => $simulator !== null ? self::remoteRows($built['grid'], $simulator, $built['preset']) : [],
            'types' => self::publicTypes(self::types($environment)),
            'options' => [],
            'warnings' => $built['warnings'],
        ];
    }

    /**
     * The grid a simulator joins and the plan of the simulator, with its first region, and what is wrong with the answers.
     *
     * @return array{problems:array<string,string>,corrected:array<string,string>,grid:?Grid,simulator:?SimulatorPlan,preset:string,warnings:list<string>}
     */
    private static function buildRemote(Environment $environment, array $answers): array
    {
        $problems = [];
        $corrected = [];
        $type = strtolower(trim((string) ($answers['type'] ?? '')));
        $preset = self::kindOf($environment, $type) === 'preset' ? $type : '';
        $presets = new Presets([], $environment->layout->etcRoot());
        $remote = RemoteGridPlanner::with([
            'environment' => $environment,
            'presets' => $presets->names(),
            'describe' => static fn(string $name): ?array => $presets->describe($name),
            'says' => self::$says,
        ]);
        if ($preset !== '') {
            $given = $remote->presetAnswers($preset) ?? [];
        } else {
            $uri = trim((string) ($answers['login_uri'] ?? ''));
            $port = trim((string) ($answers['private_port'] ?? ''));
            if ($uri === '') {
                $problems['login_uri'] = _('required');
            } elseif (\OpenSim\Hypergrid\Destination::loginParts($uri) === null) {
                $problems['login_uri'] = _('invalid (host:port)');
            }
            $port !== '' && ($message = Grid::validatePort($port)) !== null && ($problems['private_port'] = $message);
            $given = ['address' => $uri, 'private_port' => $port];
        }
        $none = [
            'problems' => $problems,
            'corrected' => $corrected,
            'grid' => null,
            'simulator' => null,
            'preset' => $preset,
            'warnings' => [],
        ];
        if (isset($problems['login_uri'])) {
            return $none;
        }
        $result = $remote->plan($given, $preset);
        if ($result['error'] !== null || $result['grid'] === null) {
            $problems['login_uri'] = (string) ($result['error'] ?? _('invalid'));

            return ['problems' => $problems] + $none;
        }
        $grid = $result['grid'];

        // Its first simulator, with its region, and the account of the grid that owns the estate
        $ui = new NullUi();
        $database = new DatabaseService($ui);
        $region = trim((string) ($answers['name'] ?? '')) !== '' ? trim((string) $answers['name']) : 'Welcome';
        $simulators = SimulatorPlanner::forGrid($grid, $database, $ui, null, $region);
        $owner =
            ['estate_owner' => trim((string) ($answers['avatar'] ?? ''))] +
            array_intersect_key(
                $answers,
                array_flip(['database_host', 'database_name', 'database_user', 'database_password']),
            );
        $owner += self::portValues($answers, ['http_port' => 'http_port', 'console_port' => 'console_port']);
        if (is_array($answers['setup'] ?? null)) {
            $owner = array_replace($owner, SetupFile::simulatorValues($answers['setup'], 0));
        }
        $fixes = self::review($simulators->fields(), $simulators->problems($owner), $owner, $problems, $corrected);
        $simulator = $simulators->plan($fixes + $owner);
        $existingRegion = (string) ($answers['existing_region'] ?? ($answers['setup']['simulators'][0]['regions'][0]['source'] ?? ''));
        if ($existingRegion !== '') {
            if (trim((string) ($answers['database_name'] ?? '')) === '') {
                $problems['database_name'] = _('The existing simulator database name is required.');
            }
            try {
                $description = RegionAdoption::describe($existingRegion);
                $registered = isset(self::$region['registeredUuid'])
                    ? (self::$region['registeredUuid'])($description['name'])
                    : RobustGrid::regionUuid($grid->baseHostname(), $grid->privatePort(), $description['name']);
                $registeredName = $description['name'];
                if ($registered === false) {
                    $registeredName = isset(self::$region['registeredName'])
                        ? (self::$region['registeredName'])($description['uuid'])
                        : RobustGrid::regionName($grid->baseHostname(), $grid->privatePort(), $description['uuid']);
                }
                RegionAdoption::apply($simulator, $existingRegion, $registered, $registeredName);
            } catch (\InvalidArgumentException $error) {
                $problems['existing_region'] = $error->getMessage();
            }
        } else {
            $regions = RegionPlanner::forSimulator(
                $simulator,
                $grid,
                $database,
                defaultName: $region,
                context: self::$region,
            );
            $regionAnswers = is_array($answers['setup'] ?? null) ? SetupFile::regionValues($answers['setup'], 0, 0) : ['name' => $region];
            $regionAnswers += self::portValues($answers, ['port' => 'region_port']);
            self::review(
                $regions->fields(),
                $regions->problems($regionAnswers),
                $regionAnswers,
                $problems,
                $corrected,
                ['port' => 'region_port'],
            );
            $regions->apply($simulator, $regionAnswers);
        }

        return [
            'problems' => $problems,
            'corrected' => $corrected,
            'grid' => $grid,
            'simulator' => $simulator,
            'preset' => $preset,
            'warnings' => $result['warnings'],
        ];
    }

    /**
     * What the setup of a simulator that joins a grid will do, by section.
     *
     * @return list<array{section:string,label:string,value:string}>
     */
    private static function remoteRows(Grid $grid, SimulatorPlan $simulator, string $preset): array
    {
        $rows = [];
        $add = static function (string $section, string $label, string $value) use (&$rows): void {
            $rows[] = ['section' => $section, 'label' => $label, 'value' => $value];
        };
        $console =
            $simulator->consoleMode === 'rest'
                ? "remote, port {$simulator->consolePort}, user {$simulator->consoleUser}"
                : 'screen session';

        $add('grid', 'Grid', "{$grid->name()} ({$grid->nick()})" . ($preset !== '' ? " - preset $preset" : ''));
        $add('grid', 'Login URI', "http://{$grid->baseHostname()}:{$grid->publicPort()}");
        $add('grid', 'Private port', (string) $grid->privatePort());
        $add('grid', 'Hypergrid', $grid->isHypergrid() ? 'yes' : 'no');
        $add('grid', 'Grid folder', $simulator->gridDir);
        $add('simulator', 'Simulator', "{$simulator->simulatorName} ({$simulator->slug})");
        $add('simulator', 'HTTP port', (string) $simulator->httpPort);
        $add('simulator', 'Console', $console);
        $add(
            'simulator',
            'Simulator database',
            "{$simulator->dbName} @ {$simulator->dbHost} (user {$simulator->dbUser})",
        );
        $add('simulator', 'Estate', $simulator->estateName);
        $add('simulator', 'Estate owner', $simulator->estateOwner . ' (an account of the grid)');
        $add('simulator', 'Simulator config', $simulator->iniPath());
        $add('region', 'Region', $simulator->regionName);
        $add('region', 'Location', $simulator->regionLocation);
        $add('region', 'Port', (string) $simulator->regionPort);
        $add('owner', 'Avatar', $simulator->estateOwner);

        return $rows;
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
    private static function review(
        FieldSet $fields,
        array $fieldProblems,
        array $given,
        array &$problems,
        array &$corrected,
        array $questions = [],
    ): array {
        foreach ($fieldProblems as $field => $message) {
            $problems[$questions[$field] ?? self::QUESTIONS[$field] ?? $field] ??= $message;
        }
        $fixes = $fields->corrections($given);
        foreach ($fixes as $field => $value) {
            $corrected[$questions[$field] ?? self::QUESTIONS[$field] ?? $field] = $value;
        }

        return $fixes;
    }

    /**
     * Every value the setup defines, in the order a person reads them, by section (grid, simulator, region, owner): what the planners
     * proposed and the answers, so that nothing the setup will do is a surprise.
     *
     * @param array<string,bool> $chosen the options, by name
     * @param array<string,string> $labels the labels of the presets, by name
     * @return list<array{section:string,label:string,value:string}>
     */
    private static function rows(
        GridPlan $plan,
        SimulatorPlan $simulator,
        bool $standalone,
        string $email,
        array $chosen,
        array $labels,
    ): array {
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
        $add(
            'grid',
            'Region spacing',
            $plan->regionSpacing === 0 ? 'side by side' : "{$plan->regionSpacing} free block(s)",
        );
        $plan->coreDirectory !== '' && $add('grid', 'Core', $plan->coreDirectory);
        $standalone || $add('grid', 'Ports', "public {$plan->publicPort}, private {$plan->privatePort}");
        $standalone || $add('grid', 'Console', $console($plan->consoleMode, $plan->consolePort, $plan->consoleUser));
        $add('grid', 'Web URL', $plan->webUrl);
        $plan->helpers &&
            $add(
                'grid',
                'Web server',
                ['caddy' => 'Caddy', 'apache' => 'Apache', 'nginx' => 'nginx'][$plan->webServer] ?? $plan->webServer,
            );
        $add(
            'grid',
            $standalone ? 'Database' : 'Grid database',
            $database($plan->dbName, $plan->dbHost, $plan->dbUser),
        );
        $standalone || $add('grid', 'Robust config', $plan->robustIni());
        $add('grid', 'Grid folder', $plan->etcDirectory);

        // What the person chose, then what is on whatever the answers (the helpers and what they serve)
        $add('options', 'Hypergrid', $yes($plan->enableHypergrid));
        $add('options', 'SSL', $yes($chosen['ssl'] ?? false) . ' (not implemented)');
        $add(
            'options',
            'Cross-grid search',
            $plan->search === 'shared' ? 'yes, the shared provider (2do.directory)' : 'no, the search of this grid',
        );
        $add('options', 'Helpers', $plan->helpers ? "yes, served at {$plan->webUrl}{$plan->helpersPath}" : 'no');
        // The presets that are on, asked or not
        foreach ($plan->options as $name) {
            isset($labels[$name]) && $add('options', $labels[$name], 'yes');
        }
        $add('options', 'Destination guide', $yes($plan->helpers));
        $add('options', 'Grid status', $yes($plan->helpers));

        $add('simulator', 'Simulator', "{$simulator->simulatorName} ({$simulator->slug})");
        $add('simulator', 'HTTP port', (string) $simulator->httpPort);
        $add(
            'simulator',
            'Console',
            $console($simulator->consoleMode, $simulator->consolePort, $simulator->consoleUser),
        );
        $standalone ||
            $add(
                'simulator',
                'Simulator database',
                $database($simulator->dbName, $simulator->dbHost, $simulator->dbUser),
            );
        $add('simulator', 'Estate', $simulator->estateName);
        $add(
            'simulator',
            'Estate owner',
            $simulator->estateOwner . ($simulator->createOwner ? ' (account to create)' : ''),
        );
        $add('simulator', 'Simulator config', $simulator->iniPath());

        $add('region', 'Region', $simulator->regionName);
        $add('region', 'Location', $simulator->regionLocation);
        $add('region', 'Port', (string) $simulator->regionPort);
        $simulator->regionRoles !== [] && $add('region', 'Roles', implode(', ', $simulator->regionRoles));

        $add('owner', 'Avatar', $simulator->estateOwner . ($email !== '' ? " <$email>" : ''));

        return $rows;
    }
}
