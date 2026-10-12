<?php

declare(strict_types=1);

/**
 * The reading of the ini files: sections, quotes, comments, the files a file includes, the constants, the config directory of
 * Robust.
 */

use OpenSim\Config\ConfigReader;
use OpenSim\Config\ConnectionString;

/** A folder with files in it, in a temporary tree: name => content. */
function reader_folder(array $files): string
{
    $dir = test_tmp() . '/reader-' . bin2hex(random_bytes(4));
    mkdir($dir);
    foreach ($files as $name => $content) {
        is_dir(dirname("$dir/$name")) || mkdir(dirname("$dir/$name"), 0o755, true);
        file_put_contents("$dir/$name", $content);
    }

    return $dir;
}

describe('Parsing a text', function () {
    test('gives the sections and the keys, quotes removed, comments and blank lines skipped', function () {
        $ini = ConfigReader::parse("; a comment\n# another\n\n[Const]\r\n    BaseURL = \"http://x\"  ; why\r\n    Port = 8002\n[Empty]\n[Other]\nNoValue =\n");

        expect($ini)->toBe(['Const' => ['BaseURL' => 'http://x', 'Port' => '8002'], 'Empty' => [], 'Other' => ['NoValue' => '']]);
    });

    test('keeps the first of a key given twice in a section', function () {
        expect(ConfigReader::parse("[A]\nk = 1\nk = 2\n[B]\nk = 3\n"))->toBe(['A' => ['k' => '1'], 'B' => ['k' => '3']]);
    });

    test('keeps a value that holds an equal sign', function () {
        expect(ConfigReader::parse("[Db]\nConnectionString = \"Data Source=localhost;Database=a;\"\n")['Db']['ConnectionString'])->toBe('Data Source=localhost;Database=a;');
    });

    test('says nothing of a file that cannot be read', function () {
        expect(ConfigReader::file('/nonexistent.ini'))->toBe([])->and(ConfigReader::load('/nonexistent.ini'))->toBe([]);
    });
});

describe('Loading a file', function () {
    test('reads the files it includes after it, so they win, and each file once even in a loop', function () {
        $dir = reader_folder([
            'bin/OpenSim.ini' => "[Architecture]\n    Include-Architecture = \"config-include/Grid.ini\"\n[Startup]\n    a = 1\n    b = main\n",
            'bin/config-include/Grid.ini' => "[Modules]\n    Include-Loop = \"../OpenSim.ini\"\n    b = 2\n[Startup]\n    b = included\n",
        ]);

        $ini = ConfigReader::load("$dir/bin/OpenSim.ini");

        expect($ini['Modules']['b'])->toBe('2')->and($ini['Startup'])->toBe(['a' => '1', 'b' => 'included']);
    });

    test('finds an included file from the folder above too, where OpenSimulator is run from', function () {
        $dir = reader_folder([
            'bin/etc/OpenSim.ini' => "[A]\nInclude-X = \"config-include/x.ini\"\n",
            'bin/config-include/x.ini' => "[X]\nfound = yes\n",
        ]);

        expect(ConfigReader::load("$dir/bin/etc/OpenSim.ini")['X']['found'])->toBe('yes');
    });

    test('keeps the references as written when asked, and replaces them otherwise, even those of another file', function () {
        $dir = reader_folder(['a.ini' => "[Const]\nHost = \"old.example.org\"\nURL = \"http://\${Const|Host}\"\n[Net]\nelsewhere = \"\${Const|Other}/x\"\n"]);

        expect(ConfigReader::load("$dir/a.ini", [], false)['Const']['URL'])
            ->toBe('http://${Const|Host}')
            ->and(ConfigReader::load("$dir/a.ini")['Const']['URL'])
            ->toBe('http://old.example.org')
            ->and(ConfigReader::load("$dir/a.ini", ['Other' => 'https://o'])['Net']['elsewhere'])->toBe('https://o/x');
    });

    test('reads the config directory of Robust when asked', function () {
        $dir = reader_folder([
            'bin/Robust.HG.ini' => "[Startup]\n    ConfigDirectory = \"robust-include\"\n",
            'bin/robust-include/extra.ini' => "[Modules]\n    MyModule = \"on\"\n",
        ]);

        expect(ConfigReader::load("$dir/bin/Robust.HG.ini"))
            ->not->toHaveKey('Modules')
            ->and(ConfigReader::load("$dir/bin/Robust.HG.ini", [], false, true)['Modules']['MyModule'])->toBe('on');
    });
});

describe('Constants', function () {
    test('are replaced a few levels deep, by the name as written or without the case, the unknown ones staying', function () {
        $ini = ConfigReader::expand(['Const' => ['Host' => 'h', 'URL' => 'http://${Const|Host}'], 'A' => ['x' => '${Const|url}/a', 'y' => '${Const|Nope}']]);

        expect($ini['A'])->toBe(['x' => 'http://h/a', 'y' => '${Const|Nope}']);
    });
});

describe('Finding a key', function () {
    test('gives the first value in any section, whichever the case of the key', function () {
        $ini = ['A' => ['one' => '1'], 'B' => ['GridName' => 'first', 'Other' => 'x'], 'C' => ['gridname' => 'second']];

        expect(ConfigReader::find($ini, 'gridname'))->toBe('first')->and(ConfigReader::find($ini, 'none'))->toBeNull();
    });
});

describe('A connection string', function () {
    test('is read in any order, the port of the source apart', function () {
        $db = ConnectionString::parse('Password=s3cr3t;User ID=opensim;Database=grid;Data Source=db.example.org:3307;Old Guids=true;');

        expect($db->source)->toBe('db.example.org:3307')
            ->and($db->host)->toBe('db.example.org')
            ->and($db->port)->toBe(3307)
            ->and([$db->database, $db->user, $db->password])->toBe(['grid', 'opensim', 's3cr3t']);
    });

    test('is written as OpenSimulator has it, and read back', function () {
        $text = (string) ConnectionString::for('localhost', 'grid', 'opensim', 'pw');

        expect($text)->toBe('Data Source=localhost;Database=grid;User ID=opensim;Password=pw;Old Guids=true;')
            ->and(ConnectionString::parse($text)->key())->toBe('opensim@localhost/grid');
    });

    test('is nothing without a database to connect to', function () {
        expect(ConnectionString::parse(''))->toBeNull()
            ->and(ConnectionString::parse('Data Source=localhost;User ID=a;'))->toBeNull();
    });
});
