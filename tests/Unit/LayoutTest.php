<?php

declare(strict_types=1);

/**
 * Where an install keeps its files, from opensim.conf.
 */

use OpenSim\Config\Layout;

/** An opensim.conf in a temporary folder. */
function layout_conf(string $text): string
{
    $dir = sys_get_temp_dir() . '/layout-' . bin2hex(random_bytes(4));
    mkdir($dir);
    file_put_contents("$dir/opensim.conf", $text);

    return "$dir/opensim.conf";
}

describe('layout of an install', function () {
    test('is [Defaults], the profile it points to over them', function () {
        $layout = Layout::load(layout_conf("[Defaults]\nDefaultProfile = opensim-0.9.3.0\nEtcRoot = /etc/opensim\nSystemUser = opensim\n\n[opensim-0.9.3.0]\nCoreDirectory = /usr/share/opensim/0.9.3.0\nEtcRoot = /srv/etc\nVarRoot = /var/lib/opensim\n"));

        expect($layout->profile())
            ->toBe('opensim-0.9.3.0')
            ->and($layout->etcRoot())->toBe('/srv/etc')
            ->and($layout->varRoot())->toBe('/var/lib/opensim')
            ->and($layout->systemUser())->toBe('opensim')
            ->and($layout->get('CoreDirectory'))->toBe('/usr/share/opensim/0.9.3.0');
    });

    test('can be another profile than the default one', function () {
        $file = layout_conf("[Defaults]\nDefaultProfile = a\n\n[a]\nEtcRoot = /a\n\n[b]\nEtcRoot = /b\n");

        expect(Layout::load($file, [], 'b')->etcRoot())->toBe('/b');
    });

    test('has the defaults it is given for what the file does not say', function () {
        $layout = Layout::load(layout_conf("[Defaults]\nEtcRoot = /etc/opensim\n"), ['EtcRoot' => '/nope', 'CoreRoot' => '/opt/core']);

        expect($layout->etcRoot())->toBe('/etc/opensim')->and($layout->coreRoot())->toBe('/opt/core');
    });

    test('is made of the defaults only when there is no file', function () {
        $layout = Layout::load('/no/such/opensim.conf', ['EtcRoot' => '/opt/etc']);

        expect($layout->file())->toBeNull()->and($layout->etcRoot())->toBe('/opt/etc')->and($layout->systemUser())->toBe('');
    });

    test('names the modules enabled for the regions', function () {
        $layout = new Layout(['EnabledModules' => 'gloebit, opensimsearch']);

        expect($layout->enabledModules())->toBe(['gloebit', 'opensimsearch'])->and((new Layout())->enabledModules())->toBe([]);
    });

    test('has the core of its CoreDirectory, else the latest one of its core root', function () {
        $root = sys_get_temp_dir() . '/layout-cores-' . bin2hex(random_bytes(4));
        foreach (['0.9.2.2', '0.9.3.0'] as $version) {
            mkdir("$root/$version/bin", 0o755, true);
            touch("$root/$version/bin/OpenSim.dll");
        }

        expect((new Layout(['CoreRoot' => $root]))->core()?->name())
            ->toBe('0.9.3.0')
            ->and((new Layout(['CoreRoot' => $root, 'CoreDirectory' => "$root/0.9.2.2"]))->core()?->name())->toBe('0.9.2.2')
            ->and((new Layout(['CoreRoot' => $root, 'CoreDirectory' => "$root/gone"]))->core()?->name())->toBe('0.9.3.0')
            ->and((new Layout(['CoreRoot' => "$root/none"]))->core())->toBeNull();
    });
});

describe('layout a front-end works with', function () {
    test('is what its environment says, then opensim.conf, then the defaults of an install by the book', function () {
        $file = layout_conf("[Defaults]\nEtcRoot = /srv/etc\nVarRoot = /srv/var\n");
        $layout = Layout::resolve(['OPENSIM_CONF' => $file, 'OPENSIM_VAR_ROOT' => '/tmp/mine', 'OPENSIM_GRID' => 'not a setting of the layout']);

        expect($layout->file())->toBe($file)
            ->and($layout->varRoot())->toBe('/tmp/mine')
            ->and($layout->etcRoot())->toBe('/srv/etc')
            ->and($layout->cacheRoot())->toBe('/var/cache/opensim')
            ->and($layout->all())->not->toHaveKey('Grid');
    });

    test('is the defaults of the system layout when nothing says otherwise, and the roots alone are enough', function () {
        $layout = Layout::resolve(['OPENSIM_CONF' => layout_conf("[Defaults]\n"), 'OPENSIM_CORE_ROOT' => '/opt/cores', 'OPENSIM_DATA_ROOT' => '/opt/data']);

        expect($layout->etcRoot())->toBe('/etc/opensim')
            ->and($layout->coreRoot())->toBe('/opt/cores')
            ->and($layout->dataRoot())->toBe('/opt/data')
            ->and($layout->logsRoot())->toBe('/var/log/opensim');
    });

    test('refuses an opensim.conf named in the environment that does not exist', function () {
        expect(fn() => Layout::resolve(['OPENSIM_CONF' => '/nonexistent/opensim.conf']))->toThrow(RuntimeException::class, '/nonexistent/opensim.conf');
    });

    test('names each setting in the environment the same way', function () {
        expect(Layout::environmentName('EtcRoot'))->toBe('OPENSIM_ETC_ROOT')
            ->and(Layout::environmentName('CoreDirectory'))->toBe('OPENSIM_CORE_DIRECTORY')
            ->and(Layout::environmentName('SystemUser'))->toBe('OPENSIM_SYSTEM_USER');
    });
});

describe('layouts of an install', function () {
    test('put the folders where opensim setup proposes them', function () {
        expect(OpenSim\Config\DirectoryLayout::roots('system', '', '0.9.3.0'))->toBe([
            'CoreRoot' => '/usr/local/share/opensim',
            'CoreDirectory' => '/usr/local/share/opensim/0.9.3.0',
            'EtcRoot' => '/etc/opensim',
            'VarRoot' => '/var/lib/opensim',
            'LogsRoot' => '/var/log/opensim',
            'CacheRoot' => '/var/cache/opensim',
            'DataRoot' => '/var/lib/opensim/data',
        ])
            ->and(OpenSim\Config\DirectoryLayout::roots('bundled', '/opt/opensim', '0.9.3.0')['DataRoot'])->toBe('/opt/opensim/var/data')
            ->and(OpenSim\Config\DirectoryLayout::roots('flat', '/home/me', '0.9.3.0')['EtcRoot'])->toBe('/home/me/opensim-0.9.3.0/bin')
            ->and(OpenSim\Config\DirectoryLayout::roots('system'))->not->toHaveKey('CoreDirectory');
    });
});
