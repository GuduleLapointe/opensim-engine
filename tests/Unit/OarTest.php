<?php
/**
 * OpenSimulator archives: made from a folder, read, checked.
 */

define('OPENSIM_ENGINE', true);
require_once dirname(__DIR__, 2) . '/class-oar.php';

/** A folder laid out as the content of an archive. */
function oar_source(): string
{
    $dir = sys_get_temp_dir() . '/oar-' . bin2hex(random_bytes(4));
    foreach (['assets', 'landdata', 'objects', 'settings', 'terrains'] as $folder) {
        mkdir("$dir/$folder", 0o755, true);
    }
    file_put_contents(
        "$dir/archive.xml",
        '<?xml version="1.0" encoding="utf-16"?><archive major_version="0" minor_version="8">'
        . '<assets_included>True</assets_included><region_info><size_in_meters>256,256</size_in_meters></region_info></archive>',
    );
    file_put_contents("$dir/objects/Fix default parcel name_128-128-026__97db21dc-5d99-4f44-9b7f-e96d69c869fc.xml", '<SceneObjectGroup />');
    file_put_contents("$dir/landdata/61412c39-9396-4b81-a235-705ded76f2e4.xml", '<LandData><Name>Your Parcel</Name></LandData>');
    file_put_contents("$dir/settings/Welcome.xml", '<RegionSettings />');
    file_put_contents("$dir/terrains/Welcome.r32", str_repeat("\0", 64));
    file_put_contents("$dir/assets/14280bc4-6d8f-4d8f-839c-da2ab87e918b_script.lsl", "default { state_entry() {} }\n");
    file_put_contents("$dir/.DS_Store", 'junk');

    return $dir;
}

it('packs a folder and reads the archive back', function () {
    $dir = oar_source();
    $oar = "$dir.oar";
    OpenSim_Oar::pack($dir, $oar);

    $entries = OpenSim_Oar::entries($oar);
    expect($entries[0])->toBe('archive.xml')
        ->and($entries)->toContain('assets/14280bc4-6d8f-4d8f-839c-da2ab87e918b_script.lsl')
        ->and($entries)->not->toContain('.DS_Store')
        ->and(count($entries))->toBe(6);
});

it('tells what is in an archive', function () {
    $dir = oar_source();
    OpenSim_Oar::pack($dir, "$dir.oar");

    expect(OpenSim_Oar::info("$dir.oar"))->toBe([
        'version' => '0.8', 'size' => [256, 256], 'assets_included' => true,
        'objects' => 1, 'parcels' => 1, 'assets' => 1, 'terrains' => 1, 'settings' => 1,
    ]);
});

it('unpacks to the same files', function () {
    $dir = oar_source();
    OpenSim_Oar::pack($dir, "$dir.oar");
    $out = "$dir-out";
    OpenSim_Oar::unpack("$dir.oar", $out);

    expect(file_get_contents("$out/assets/14280bc4-6d8f-4d8f-839c-da2ab87e918b_script.lsl"))
        ->toBe("default { state_entry() {} }\n")
        ->and(file_get_contents("$out/archive.xml"))->toBe(file_get_contents("$dir/archive.xml"));
});

it('checks a good archive and finds nothing', function () {
    $dir = oar_source();
    OpenSim_Oar::pack($dir, "$dir.oar");

    expect(OpenSim_Oar::check("$dir.oar"))->toBe([]);
});

it('finds what is wrong in an archive', function () {
    $dir = oar_source();
    file_put_contents("$dir/objects/broken.xml", '<SceneObjectGroup>');
    file_put_contents("$dir/stray.txt", 'x');
    foreach (glob("$dir/assets/*") as $asset) {
        unlink($asset);
    }
    OpenSim_Oar::pack($dir, "$dir.oar");

    $problems = OpenSim_Oar::check("$dir.oar");
    expect($problems)->toContain('objects/broken.xml does not parse')
        ->and($problems)->toContain('unknown entry: stray.txt')
        ->and($problems)->toContain('the archive says assets are included, and has none');
});

it('refuses what is not an archive', function () {
    $file = tempnam(sys_get_temp_dir(), 'oar');
    file_put_contents($file, 'plain text');

    expect(fn() => OpenSim_Oar::entries($file))->toThrow(RuntimeException::class)
        ->and(OpenSim_Oar::check($file))->not->toBe([]);
});

it('refuses a folder without a control file', function () {
    $dir = sys_get_temp_dir() . '/oar-empty-' . bin2hex(random_bytes(4));
    mkdir($dir);

    expect(fn() => OpenSim_Oar::pack($dir, "$dir.oar"))->toThrow(RuntimeException::class);
});

it('refuses an entry that goes out of the folder', function () {
    $tar = tempnam(sys_get_temp_dir(), 'oar') . '.tar';
    $archive = new PharData($tar);
    $archive->addFromString('archive.xml', '<archive major_version="0" minor_version="8" />');
    $archive->addFromString('../evil.txt', 'x');
    $archive->compress(Phar::GZ);
    $oar = "$tar.gz";

    expect(fn() => OpenSim_Oar::unpack($oar, sys_get_temp_dir() . '/oar-safe-' . bin2hex(random_bytes(4))))
        ->toThrow(RuntimeException::class)
        ->and(OpenSim_Oar::check($oar))->toContain('unsafe entry: ../evil.txt');
});
