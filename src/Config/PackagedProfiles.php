<?php

declare(strict_types=1);

namespace OpenSim\Config;


/**
 * What the packages of the releases (opensim-<version>, in /usr/share/opensim) do to opensim.conf when they come and go: one
 * install profile per release, the first one the default, the profile of a release that was removed forgotten. The file is edited
 * in place, what the administrator wrote in it stays (comments, other settings).
 */
final class PackagedProfiles
{
    /** What a profile of a release takes from [Defaults]: the locations of the install, and who runs the instances. */
    private const INHERITED = ['DirectoryLayout', 'CoreRoot', 'EtcRoot', 'VarRoot', 'LogsRoot', 'CacheRoot', 'DataRoot', 'SystemUser'];

    public function __construct(private readonly string $conf = '/etc/opensim/opensim.conf', private readonly string $coresRoot = '/usr/share/opensim')
    {
    }

    /**
     * Make the profiles follow the releases that are installed.
     *
     * @return list<string> what was changed, one line each
     */
    public function update(): array
    {
        if (!is_file($this->conf)) {
            return [];
        }
        $ini = IniEditor::load($this->conf);
        $before = $ini->toString();
        $notes = [];
        $all = ConfigReader::file($this->conf);
        $defaults = $all['Defaults'] ?? [];
        $cores = $this->cores();

        foreach ($cores as $version => $core) {
            $profile = "opensim-$version";
            if (isset($all[$profile])) {
                continue;
            }
            // Self-contained: the locations of [Defaults], and this release
            foreach (self::INHERITED as $key) {
                isset($defaults[$key]) && $ini->set($profile, $key, $defaults[$key]);
            }
            $ini->set($profile, 'OpensimVersion', $version);
            $ini->set($profile, 'CoreDirectory', $core);
            $ini->set($profile, 'Runtime', 'dotnet');
            $notes[] = "profile $profile added";
        }

        foreach ($all as $profile => $values) {
            $core = $values['CoreDirectory'] ?? '';
            if ($profile === 'Defaults' || !str_starts_with($core, rtrim($this->coresRoot, '/') . '/') || is_dir($core)) {
                continue;
            }
            $ini->removeSection($profile);
            if ($ini->get('Defaults', 'DefaultProfile') === $profile) {
                $ini->remove('Defaults', 'DefaultProfile');
            }
            $notes[] = "profile $profile removed, its release is gone";
        }

        if ($ini->get('Defaults', 'DefaultProfile') === null && $cores !== []) {
            // The latest release, or else another build (the unstable one for instance)
            $releases = array_filter(array_keys($cores), static fn(string $version): bool => preg_match('/^\d+(\.\d+)+$/', $version) === 1);
            $version = (string) (end($releases) ?: array_key_last($cores));
            $ini->set('Defaults', 'DefaultProfile', "opensim-$version");
            $ini->set('Defaults', 'OpensimVersion', $version);
            $ini->set('Defaults', 'CoreDirectory', $cores[$version]);
            $ini->set('Defaults', 'Runtime', 'dotnet');
            $notes[] = "default profile: opensim-$version";
        }

        $ini->toString() !== $before && $ini->save($this->conf);

        return $notes;
    }

    /**
     * Enable modules for the install the first time: when [Defaults] has no EnabledModules at all. An administrator's setting is
     * kept, an empty one too.
     *
     * @return bool whether it was set
     */
    public function enableModulesOnce(string $modules): bool
    {
        if (!is_file($this->conf)) {
            return false;
        }
        $ini = IniEditor::load($this->conf);
        if ($ini->get('Defaults', 'EnabledModules') !== null) {
            return false;
        }
        $ini->set('Defaults', 'EnabledModules', $modules);
        $ini->save($this->conf);

        return true;
    }

    /**
     * The releases installed, in the order of sort -V (numbers first, then names), the lowest first: a folder of the root with the OpenSim assembly in its bin.
     *
     * @return array<string,string> version => folder
     */
    private function cores(): array
    {
        $cores = [];
        foreach (glob(rtrim($this->coresRoot, '/') . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            // OpenSim.exe in the releases, only OpenSim.dll in the builds made on Linux
            if (is_file("$dir/bin/OpenSim.exe") || is_file("$dir/bin/OpenSim.dll")) {
                $cores[basename($dir)] = $dir;
            }
        }
        uksort($cores, 'strnatcmp');

        return $cores;
    }
}
