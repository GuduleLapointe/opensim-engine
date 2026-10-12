<?php
/**
 * The config of an OpenSimulator environment: the ini files as a nested array that follows their structure, the files they
 * include, the constants, the first file that says a setting.
 */


/**
 * A grid folder in a temporary tree.
 *
 * @param  array<string,string> $files name => content (Robust.HG.ini, OpenSim.ini, sims/one.ini, config-include/x.ini...)
 * @return string the folder
 */
function config_folder(array $files): string
{
    $dir = sys_get_temp_dir() . '/engine-config-' . bin2hex(random_bytes(4));
    mkdir($dir);
    foreach ($files as $name => $content) {
        is_dir(dirname("$dir/$name")) || mkdir(dirname("$dir/$name"), 0o755, true);
        file_put_contents("$dir/$name", $content);
    }

    return $dir;
}

const ROBUST_INI = <<<'INI'
; Robust of the grid
[Const]
    BaseHostname = "play.example.org"
    BaseURL = "http://${Const|BaseHostname}"
    WebURL = "https://${Const|BaseHostname}"
    PublicPort = 8002
[DatabaseService]
    ConnectionString = "Data Source=localhost;Database=alpha_robust;User ID=opensim;Password=s3cret;"
[Hypergrid]
    GatekeeperURI = "${Const|BaseURL}:${Const|PublicPort}"
[GridInfoService]
    gridname = "Alpha World"
    SearchURL = "${Const|WebURL}/search"
INI;

describe('Reading a file', function () {
    test('is as OpenSimulator reads it: sections, quotes, comments, constants replaced', function () {
        $dir = config_folder(['Robust.HG.ini' => ROBUST_INI]);

        $ini = OpenSim_Config::read_ini("$dir/Robust.HG.ini");

        expect($ini['Const']['BaseURL'])
            ->toBe('http://play.example.org')
            ->and($ini['Hypergrid']['GatekeeperURI'])
            ->toBe('http://play.example.org:8002')
            ->and($ini['GridInfoService']['gridname'])
            ->toBe('Alpha World')
            ->and(OpenSim_Config::read_ini('/nonexistent.ini'))
            ->toBe([]);
    });

    test('keeps the first of a key given twice in a section, as OpenSimulator does', function () {
        $dir = config_folder(['twice.ini' => "[DatabaseService]\nConnectionString = \"first\"\nConnectionString = \"second\"\n"]);

        expect(OpenSim_Config::read_ini("$dir/twice.ini")['DatabaseService']['ConnectionString'])->toBe('first');
    });
});

describe('The files a file includes', function () {
    /** The merged settings of the main file of a test folder. */
    $chain = fn(array $files, string $main = 'OpenSim.ini') => OpenSim_Config::read(config_folder($files) . "/$main");

    test('are read after the file that includes them, in any section, a relative path being relative to that file', function () use ($chain) {
        $ini = $chain([
            'OpenSim.ini' => "[Economy]\neconomymodule = Gloebit\n[Architecture]\nInclude-Gloebit = \"config-include/Gloebit.ini\"\n",
            'config-include/Gloebit.ini' => "[Gloebit]\nGLBEnvironment = sandbox\n",
        ]);

        expect($ini['Gloebit']['GLBEnvironment'])->toBe('sandbox')->and($ini['Economy']['economymodule'])->toBe('Gloebit');
    });

    test('give their values over the ones read before, and the ones they include theirs in turn', function () use ($chain) {
        $ini = $chain([
            'OpenSim.ini' => "[A]\none = main\ntwo = main\n[Includes]\nInclude-First = \"first.ini\"\n",
            'first.ini' => "[A]\ntwo = first\n[Other]\nInclude-Second = \"sub/second.ini\"\n",
            'sub/second.ini' => "[A]\nthree = second\n[Other]\nInclude-Back = \"../OpenSim.ini\"\n",
        ]);

        // The file that includes itself again is not read twice
        expect($ini['A'])->toBe(['one' => 'main', 'two' => 'first', 'three' => 'second']);
    });

    test('can be given with a constant, an absolute path or a wildcard', function () {
        $dir = config_folder([
            'OpenSim.ini' => "[Const]\nEtc = \"ETC\"\n[Includes]\nInclude-A = \"\${Const|Etc}/a.ini\"\nInclude-B = \"ETC/more/*.ini\"\n",
            'a.ini' => "[A]\nfrom = a\n",
            'more/b.ini' => "[B]\nfrom = b\n",
            'more/c.ini' => "[C]\nfrom = c\n",
            'more/ignored.txt' => "[D]\nfrom = d\n",
        ]);
        file_put_contents("$dir/OpenSim.ini", str_replace('ETC', $dir, (string) file_get_contents("$dir/OpenSim.ini")));

        expect(array_keys(OpenSim_Config::read("$dir/OpenSim.ini")))->toBe(['Const', 'Includes', 'A', 'B', 'C']);
    });

    test('are not read when nothing includes them, nor the constants of a file that is not read', function () use ($chain) {
        $ini = $chain([
            'OpenSim.ini' => "[A]\none = 1\n",
            'config-include/Gloebit.ini' => "[Gloebit]\nGLBEnvironment = sandbox\n",
        ]);

        expect($ini)->not->toHaveKey('Gloebit');
    });

    test('have their constants replaced in the whole, whichever file has them', function () use ($chain) {
        $ini = $chain([
            'OpenSim.ini' => "[Net]\nurl = \"http://\${Const|Host}:8002\"\n[Includes]\nInclude-Const = \"const.ini\"\n",
            'const.ini' => "[Const]\nHost = \"play.example.org\"\n",
        ]);

        expect($ini['Net']['url'])->toBe('http://play.example.org:8002');
    });
});

describe('The config of a grid folder', function () {
    $folder = fn(array $more = [], string $robust = 'Robust.HG.ini') => OpenSim_Config::from_folder(
        config_folder(['MoneyServer.ini' => "[MoneyServer]\nMoneyScriptAccessKey = \"k\"\n", $robust => ROBUST_INI] + $more),
        ['Helpers' => 'Helpers.ini'],
    );

    test('is a tree that follows the structure of the ini files, a setting found by its path', function () use ($folder) {
        $config = $folder();

        expect($config->get('Robust.GridInfoService.SearchURL'))
            ->toBe('https://play.example.org/search')
            ->and($config->get('Robust.GridInfoService'))
            ->toBe(['gridname' => 'Alpha World', 'SearchURL' => 'https://play.example.org/search'])
            ->and($config->tree()['MoneyServer']['MoneyServer']['MoneyScriptAccessKey'])
            ->toBe('k')
            ->and($config->get('Robust.Nothing.Here', 'none'))
            ->toBe('none')
            ->and($config->get('OpenSim'))
            ->toBeNull();
    });

    test('has the roles of the files that are there, in the order of their authority', function () use ($folder) {
        $config = $folder(['OpenSim.ini' => "[Search]\nSearchURL = \"a\"\n", 'sims/one.ini' => "[Search]\nSearchURL = \"b\"\n", 'Helpers.ini' => "[Helpers]\nGuideSource = \"g\"\n"]);

        expect(array_keys($config->tree()))
            ->toBe(['Robust', 'OpenSim', 'sims', 'MoneyServer', 'Helpers'])
            ->and($config->has('Robust'))
            ->toBeTrue()
            ->and($config->has('sims/one'))
            ->toBeTrue()
            ->and($config->has('sims/two'))
            ->toBeFalse()
            ->and(basename((string) $config->file('Robust')))
            ->toBe('Robust.HG.ini')
            ->and(array_map('basename', $config->files()))
            ->toBe(['Robust.HG.ini', 'OpenSim.ini', 'one.ini', 'MoneyServer.ini', 'Helpers.ini']);
    });

    test('is Robust.ini for a grid that has no Robust.HG.ini', function () use ($folder) {
        expect(basename((string) $folder([], 'Robust.ini')->file('Robust')))->toBe('Robust.ini');
    });

    test('says a setting by the first file that has it, a key of a section being found whichever the file', function () use ($folder) {
        $config = $folder(['OpenSim.ini' => "[Search]\nSearchURL = \"a\"\n", 'sims/one.ini' => "[Search]\nSearchURL = \"b\"\n[Messaging]\nOfflineMessageURL = \"o\"\n"]);

        expect($config->find('Search', 'SearchURL'))
            ->toBe('a')
            ->and($config->find('Messaging', 'OfflineMessageURL'))
            ->toBe('o')
            ->and($config->find('Search', 'Nothing'))
            ->toBeNull()
            ->and($config->section('Search'))
            ->toBe(['SearchURL' => 'a']);
    });

    test('gives the settings of a section that match, with their keys', function () use ($folder) {
        $config = $folder(['OpenSim.ini' => "[DataSnapshot]\nDATA_SRV_Alpha = \"https://a/register.php\"\nOther = \"x\"\n", 'sims/one.ini' => "[DataSnapshot]\nDATA_SRV_2do = \"https://b/register.php\"\n"]);

        expect($config->keys('DataSnapshot', '/^DATA_SRV_/'))->toBe(['DATA_SRV_Alpha' => 'https://a/register.php', 'DATA_SRV_2do' => 'https://b/register.php']);
    });

    test('expands the constants of Robust in the files of the simulators', function () use ($folder) {
        $config = $folder(['sims/one.ini' => "[Messaging]\nOfflineMessageURL = \"\${Const|WebURL}/helpers/offline.php\"\n"]);

        expect($config->find('Messaging', 'OfflineMessageURL'))->toBe('https://play.example.org/helpers/offline.php');
    });

    test('is empty, and nothing is created, for a folder that is not there', function () {
        $config = OpenSim_Config::from_folder('/nonexistent/grid');

        expect($config->tree())->toBe([])->and($config->files())->toBe([])->and($config->find('A', 'b'))->toBeNull();
    });
});
