<?php

declare(strict_types=1);

/**
 * The options of a grid are presets (a folder with an option.conf and the ini files it lays over the files of the grid): the ones of the
 * kit, the ones of the administrator, enabled at the setup or later, disabled the same way.
 */

use OpenSim\Config\ConfigReader;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\GridOptions;
use OpenSim\Provisioning\GridPlan;
use OpenSim\Provisioning\OptionTemplate;
use OpenSim\Provisioning\Presets;
use OpenSim\Testing\Machine;

/** The options of the grid `demo` of a machine. */
function demo_options(Machine $machine): GridOptions
{
    return new GridOptions(Grid::find($machine->environment, 'demo'));
}

/** A preset of the administrator on a machine: its files by relative path, and its option.conf. */
function admin_option(Machine $machine, string $name, string $conf, array $files = []): void
{
    $machine->write("{$machine->etc}/presets/$name/option.conf", "[Option]\n$conf\n");
    foreach ($files as $path => $text) {
        $machine->write("{$machine->etc}/presets/$name/$path", $text);
    }
}

/** The plan of a grid `demo` that has helpers (or not), for the options it will have. */
function demo_plan(Machine $machine, bool $helpers = true): GridPlan
{
    $plan = new GridPlan();
    $plan->gridNick = 'demo';
    $plan->gridName = 'Demo World';
    $plan->gridDir = $plan->etcDirectory = $machine->grid;
    $plan->helpers = $helpers;
    $plan->webUrl = 'https://demo.example.org';
    $plan->helpersPath = '/helpers';
    $plan->search = $helpers ? 'internal' : 'disabled';

    return $plan;
}

describe('The options there are', function () {
    test('are the presets of the kit and of the administrator, the administrator\'s over the kit\'s', function () {
        $machine = new Machine();
        admin_option($machine, 'hello', 'Label = "Hello"');
        admin_option($machine, 'profiles', 'Label = "My profiles"');
        $options = demo_options($machine);

        expect(array_keys($options->templates()))
            ->toContain('search', 'profiles', 'economy-gloebit', 'offline-robust', 'ossl-extended', 'hello')
            ->and($options->template('profiles')?->label())
            ->toBe('My profiles')
            ->and($options->template('hello'))
            ->toBeInstanceOf(OptionTemplate::class)
            ->and($options->template('nothing'))
            ->toBeNull()
            // They are not grids to join
            ->and(array_keys((new Presets([], $machine->etc))->names()))
            ->not->toContain('hello', 'search', 'profiles');
    });

    test('say what they are: offered or not, on by default, their group and what they need', function () {
        $options = demo_options(new Machine());
        $gloebit = $options->template('economy-gloebit');
        $search = $options->template('search');

        expect($gloebit?->label())
            ->toBe('Economy: Gloebit')
            ->and($gloebit?->offered())
            ->toBeTrue()
            ->and($gloebit?->default())
            ->toBeFalse()
            ->and([$gloebit?->group(), $gloebit?->variant(), $gloebit?->answer()])
            ->toBe(['economy', 'gloebit', 'economy'])
            ->and($gloebit?->modules())
            ->toBe(['gloebit'])
            ->and($gloebit?->asks())
            ->toBe(['Gloebit/GLBKey', 'Gloebit/GLBSecret', 'Gloebit/GLBOwnerName'])
            ->and($search?->offered())
            ->toBeFalse()
            ->and($search?->requires())
            ->toBe(['service:query', 'service:register'])
            ->and($options->template('ossl-extended')?->answer())
            ->toBe('ossl');
    });

    test('are found by the word of a group and its variant', function () {
        $options = demo_options(new Machine());

        expect($options->resolve('economy', 'gloebit')?->name())
            ->toBe('economy-gloebit')
            ->and($options->resolve('search')?->name())
            ->toBe('search')
            ->and($options->resolve('offline', 'robust')?->name())
            ->toBe('offline-robust')
            ->and($options->resolve('economy', 'gold'))
            ->toBeNull();
    });
});

describe('An option enabled', function () {
    test('is laid over the files of the grid that have its name, and commented out when disabled', function () {
        $machine = new Machine();
        admin_option($machine, 'hello', 'Label = "Hello"', [
            'OpenSim.ini' => "[Hello]\n    Greeting = \"{{grid_name}}\"\n",
            'Robust.ini' => "[Hello]\n    RobustSays = yes\n",
            'config-include/Hello.ini' => "[Hello]\n    Extra = 1\n",
        ]);
        $machine->write($machine->grid . '/config-include/Hello.ini', "[Hello]\n    Other = kept\n");
        $options = demo_options($machine);

        $result = $options->enable('hello');

        expect($result['error'])
            ->toBeNull()
            ->and($result['simulators'])
            ->toBe(['alpha', 'beta'])
            ->and($result['robust'])
            ->toBeTrue()
            ->and($result['files'])
            ->toBe([$machine->grid . '/config-include/Hello.ini'])
            ->and(ConfigReader::load($machine->grid . '/sims/alpha.ini')['Hello']['Greeting'])
            ->toBe('Demo')
            ->and(ConfigReader::load($machine->grid . '/Robust.HG.ini')['Hello']['RobustSays'])
            ->toBe('yes')
            ->and(ConfigReader::load($machine->grid . '/config-include/Hello.ini')['Hello'])
            ->toBe(['Other' => 'kept', 'Extra' => '1'])
            ->and(Grid::at($machine->environment, $machine->grid)->options())
            ->toContain('hello');

        $again = $options->enable('hello');
        expect([$again['simulators'], $again['robust'], $again['files']])->toBe([[], false, []]);

        $off = $options->disable('hello');
        expect($off['simulators'])
            ->toBe(['alpha', 'beta'])
            ->and(file_get_contents($machine->grid . '/sims/alpha.ini'))
            ->toContain(';Greeting = "Demo"')
            ->and(file_get_contents($machine->grid . '/config-include/Hello.ini'))
            ->toContain('Other = kept')
            ->toContain(';Extra = 1')
            ->and(Grid::at($machine->environment, $machine->grid)->options())
            ->not->toContain('hello');
    });

    test('does not write a setting the grid has nothing to say for, and is refused by a grid that cannot have it', function () {
        $machine = new Machine();
        admin_option($machine, 'needy', 'Label = "Needy"', ['OpenSim.ini' => "[Needy]\n    Url = \"{{search_url}}\"\n    Name = \"{{grid_nick}}\"\n"]);
        admin_option($machine, 'helped', "Label = \"Helped\"\nRequires = helpers\nNeeds = \"{nick} has no helpers\"");
        $options = demo_options($machine);

        $result = $options->enable('needy');
        $helped = $options->enable('helped');

        expect($result['error'])
            ->toBeNull()
            ->and(ConfigReader::load($machine->grid . '/sims/alpha.ini')['Needy'])
            ->toBe(['Name' => 'demo'])
            ->and($helped['error'])
            ->toBe('demo has no helpers')
            ->and($options->enable('nothing')['error'])
            ->toContain("unknown option 'nothing'");
    });

    test('switches on as it is a connector the file has commented out', function () {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/Robust.HG.ini',
            str_replace(
                "[Network]",
                "    ; OfflineIMServiceConnector = \"\${Const|PrivatePort}/OpenSim.Addons.OfflineIM.dll:OfflineIMServiceRobustConnector\"\n[Network]",
                file_get_contents($machine->grid . '/Robust.HG.ini'),
            ),
        );
        $options = demo_options($machine);

        $on = $options->enable('offline-robust');

        expect($on['robust'])
            ->toBeTrue()
            ->and(file_get_contents($machine->grid . '/Robust.HG.ini'))
            ->toContain("\nOfflineIMServiceConnector = \"\${Const|PrivatePort}/OpenSim.Addons.OfflineIM.dll:OfflineIMServiceRobustConnector\"");

        $options->disable('offline-robust');
        expect(file_get_contents($machine->grid . '/Robust.HG.ini'))->toContain(';OfflineIMServiceConnector = ');
    });

    test('is one at a time in a group, the keys and the module it needs told, what the administrator set kept', function () {
        $machine = new Machine();
        $options = demo_options($machine);

        $beta = $options->enable('economy-beta');
        $gloebit = $options->enable('economy-gloebit');
        $economy = fn(): array => ConfigReader::load($machine->grid . '/sims/alpha.ini');

        expect($beta['error'])
            ->toBeNull()
            ->and($gloebit['error'])
            ->toBeNull()
            ->and($economy()['Economy']['economymodule'])
            ->toBe('Gloebit')
            ->and($economy()['Gloebit'])
            ->toBe(['Enabled' => 'true', 'GLBEnvironment' => 'sandbox'])
            ->and(implode("\n", $gloebit['notes']))
            ->toContain('the module gloebit is not in EnabledModules')
            ->toContain('GLBKey, GLBSecret, GLBOwnerName')
            ->and(Grid::at($machine->environment, $machine->grid)->options())
            ->toContain('economy-gloebit')
            ->not->toContain('economy-beta');

        // The environment is the administrator's after the first time, and the keys are no longer missing once given
        $machine->write(
            $machine->grid . '/sims/alpha.ini',
            str_replace('GLBEnvironment = sandbox', "GLBEnvironment = production\n    GLBKey = k\n    GLBSecret = s\n    GLBOwnerName = o", file_get_contents($machine->grid . '/sims/alpha.ini')),
        );
        $machine->write(
            $machine->grid . '/sims/beta.ini',
            file_get_contents($machine->grid . '/sims/beta.ini') . "\n[Gloebit]\n    GLBKey = k\n    GLBSecret = s\n    GLBOwnerName = o\n",
        );
        $again = $options->enable('economy-gloebit');

        expect($economy()['Gloebit']['GLBEnvironment'])
            ->toBe('production')
            ->and(implode("\n", $again['notes']))
            ->not->toContain('GLBKey');
    });

    test('takes the place of the settings of the option it replaces', function () {
        $machine = new Machine();
        $machine->write(
            $machine->grid . '/sims/alpha.ini',
            file_get_contents($machine->grid . '/sims/alpha.ini') . "\n[Messaging]\n    OfflineMessageURL = \"https://x.example.org/offline.php\"\n",
        );

        demo_options($machine)->enable('offline-robust');

        expect(ConfigReader::load($machine->grid . '/sims/alpha.ini')['Messaging'])
            ->toBe(['OfflineMessageURL' => '${Const|PrivURL}:${Const|PrivatePort}', 'OfflineMessageModule' => 'Offline Message Module V2']);
    });
});

describe('The options of a grid that is made', function () {
    test('are the ones on by default that the grid can have', function () {
        $machine = new Machine();
        $with = (new GridOptions(Grid::planned($machine->environment, demo_plan($machine))))->selection([]);
        $without = (new GridOptions(Grid::planned($machine->environment, demo_plan($machine, false))))->selection([]);

        expect($with)
            ->toBe(['offline-helpers', 'ossl-extended', 'profiles', 'search'])
            ->and($without)
            ->toBe(['offline-robust', 'ossl-extended', 'profiles']);
    });

    test('follow the answers: by the name of the option, by the answer it takes, by a group and its variant', function () {
        $machine = new Machine();
        $select = fn(array $answers, ?array $current = null): array => (new GridOptions(Grid::planned($machine->environment, demo_plan($machine))))->selection($answers, $current);

        expect($select(['ossl' => false]))
            ->not->toContain('ossl-extended')
            ->and($select(['options' => ['ossl-extended' => false, 'profiles' => true]]))
            ->not->toContain('ossl-extended')
            ->toContain('profiles')
            ->and($select(['economy' => 'gloebit']))
            ->toContain('economy-gloebit')
            ->not->toContain('economy-beta')
            ->and($select(['offline' => 'both']))
            ->toContain('offline-both')
            ->not->toContain('offline-helpers', 'offline-robust')
            ->and($select(['search' => 'disabled']))
            ->not->toContain('search')
            // A list says which are on, and no other
            ->and($select(['options' => ['profiles']]))
            ->toBe(['profiles'])
            ->and($select(['options' => []]))
            ->toBe([])
            // A grid that is changed keeps what it has, and takes what is asked
            ->and($select([], ['profiles']))
            ->toBe(['profiles'])
            ->and($select(['ossl' => true], ['profiles']))
            ->toBe(['ossl-extended', 'profiles']);
    });

    test('include the ones of the administrator, which the setup offers', function () {
        $machine = new Machine();
        admin_option($machine, 'hello', "Label = \"Hello\"\nDefault = false", ['OpenSim.ini' => "[Hello]\n    Greeting = \"{{grid_name}}\"\n"]);
        $grid = Grid::planned($machine->environment, demo_plan($machine));
        $options = new GridOptions($grid);

        expect($options->template('hello')?->offered())
            ->toBeTrue()
            ->and($options->selection([]))
            ->not->toContain('hello')
            ->and($options->selection(['options' => ['hello' => true]]))
            ->toContain('hello');
    });

    test('say what their simulators say, for a grid that has no master', function () {
        $machine = new Machine();
        $plan = demo_plan($machine);
        $plan->options = ['search', 'offline-helpers', 'profiles'];

        $layer = (new GridOptions(Grid::planned($machine->environment, $plan)))->layer();
        $settings = ConfigReader::load($machine->write($machine->root . '/layer.ini', $layer), expand: false);

        expect($settings['Search'])
            ->toBe(['Module' => 'OpenSimSearch', 'SearchURL' => 'https://demo.example.org/helpers/query.php'])
            ->and($settings['DataSnapshot'])
            ->toBe([
                'index_sims' => 'true',
                'gridname' => 'Demo World',
                'DATA_SRV_Demo' => 'https://demo.example.org/helpers/register.php',
            ])
            ->and($settings['Messaging']['OfflineMessageURL'])
            ->toBe('https://demo.example.org/helpers/offline.php')
            ->and($settings['UserProfiles']['ProfileServiceURL'])
            ->toBe('${Const|BaseURL}:${Const|PublicPort}');
    });
});
