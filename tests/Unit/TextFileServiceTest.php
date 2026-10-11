<?php

declare(strict_types=1);

/**
 * Files from the OpenSim sources, some shipped with Windows line endings, are brought to Unix ones wherever they are read or
 * copied.
 */

use OpenSim\Config\TextFileService;

/** A folder of its own for a test. */
function textfile_dir(): string
{
    $dir = test_tmp() . '/textfile-' . bin2hex(random_bytes(4));
    mkdir($dir);

    return $dir;
}

describe('TextFile', function () {
    test('brings Windows and old Mac line endings to Unix ones', function () {
        expect(TextFileService::unix("a\r\nb\rc\nd"))->toBe("a\nb\nc\nd");
        expect(TextFileService::unix("already\nunix\n"))->toBe("already\nunix\n");
    });

    test('copies a file with Unix line endings', function () {
        $dir = textfile_dir();
        file_put_contents("$dir/source.ini", "[Const]\r\nBaseURL = x\r\n");

        expect(TextFileService::copy("$dir/source.ini", "$dir/copy.ini"))->toBeTrue();
        expect(file_get_contents("$dir/copy.ini"))->toBe("[Const]\nBaseURL = x\n");
        expect(file_get_contents("$dir/source.ini"))->toContain("\r");
    });

    test('cleans a file in place, and says whether it changed', function () {
        $dir = textfile_dir();
        file_put_contents("$dir/a.ini", "[A]\r\nk = v\r\n");
        file_put_contents("$dir/b.ini", "[B]\nk = v\n");

        expect(TextFileService::clean("$dir/a.ini"))->toBeTrue();
        expect(file_get_contents("$dir/a.ini"))->toBe("[A]\nk = v\n");
        expect(TextFileService::clean("$dir/b.ini"))->toBeFalse();
        expect(TextFileService::clean("$dir/missing.ini"))->toBeFalse();
    });
});
