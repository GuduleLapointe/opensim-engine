<?php

declare(strict_types=1);

namespace OpenSim\Config;

use OpenSim\Runtime\Core;

/**
 * Where an install keeps its files: the settings of `opensim.conf`, [Defaults] first and the profile it points to over them
 * (or the one asked for). The folders of the install (etc, var, logs, cache, data, cores), the user the instances run as,
 * the modules enabled for the regions.
 *
 *     $layout = Layout::load();
 *     $layout->etcRoot();           // /etc/opensim
 *     $layout->core();              // the core the instances run from, unless their grid says another
 *
 * Nothing is created, nothing is written: reading only.
 */
final class Layout
{
    /** @var list<string> where `opensim.conf` is looked for, in this order ($HOME is the one of the caller) */
    private const FILES = ['%HOME%/.config/opensim/opensim.conf', '%HOME%/.opensim.conf', '/etc/opensim/opensim.conf'];

    private ?Core $core = null;
    private bool $coreFound = false;

    /**
     * @param array<string,string> $values the settings, [Defaults] and the profile resolved
     */
    public function __construct(
        private readonly array $values = [],
        private readonly ?string $file = null,
        private readonly string $profile = '',
    ) {}

    /**
     * The layout of the install: `opensim.conf` found in the usual places (or given), then the profile.
     *
     * @param ?string $file the config file, null to look for it
     * @param array<string,string> $defaults the values of an install by the book, for what the file does not say (EtcRoot, CoreRoot)
     * @param ?string $profile the profile, null for the default one
     */
    public static function load(?string $file = null, array $defaults = [], ?string $profile = null): self
    {
        $file ??= self::locate();
        $ini = $file !== null && is_file($file) ? ConfigReader::load($file) : [];
        $values = $ini['Defaults'] ?? [];
        $profile ??= $values['DefaultProfile'] ?? '';
        if ($profile !== '' && isset($ini[$profile])) {
            $values = $ini[$profile] + $values;
        }
        unset($values['DefaultProfile']);

        return new self($values + $defaults, $file !== null && is_file($file) ? $file : null, $profile);
    }

    /**
     * The settings of opensim.conf an environment may give as `OPENSIM_<NAME_IN_CAPITALS>` (`OPENSIM_ETC_ROOT` for EtcRoot).
     */
    public const ENVIRONMENT_KEYS = [
        'CoreRoot',
        'CoreDirectory',
        'EtcRoot',
        'VarRoot',
        'LogsRoot',
        'CacheRoot',
        'DataRoot',
        'SystemUser',
        'ModulesRoot',
        'EnabledModules',
    ];

    /**
     * The layout of a front-end: the OPENSIM_* variables of its environment, then opensim.conf, then the defaults of the system layout.
     *
     * @param ?array<string,mixed> $environment the variables of the environment, null for the ones of the process
     * @throws \RuntimeException when OPENSIM_CONF names a file that does not exist
     */
    public static function resolve(?array $environment = null, ?string $profile = null): self
    {
        $environment ??= getenv();
        $file = (string) ($environment['OPENSIM_CONF'] ?? '');
        if ($file !== '' && !is_file($file)) {
            throw new \RuntimeException("OPENSIM_CONF names $file, which does not exist");
        }
        $layout = self::load($file !== '' ? $file : null, DirectoryLayout::roots(), $profile);
        $given = [];
        foreach (self::ENVIRONMENT_KEYS as $key) {
            $value = (string) ($environment[self::environmentName($key)] ?? '');
            if ($value !== '') {
                $given[$key] = $value;
            }
        }

        return new self($given + $layout->all(), $layout->file(), $layout->profile());
    }

    /** The name a setting of opensim.conf has in an environment: EtcRoot is OPENSIM_ETC_ROOT. */
    public static function environmentName(string $key): string
    {
        return 'OPENSIM_' . strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    }

    /** The first `opensim.conf` of the usual places, null when there is none. */
    public static function locate(): ?string
    {
        $home = (string) getenv('HOME');
        foreach (self::FILES as $candidate) {
            $path = str_replace('%HOME%', $home, $candidate);
            if (($home !== '' || !str_contains($candidate, '%HOME%')) && is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /** The file the settings come from, null when there is none (the defaults only). */
    public function file(): ?string
    {
        return $this->file;
    }

    /** The profile in use, empty when the file has none. */
    public function profile(): string
    {
        return $this->profile;
    }

    /** @return array<string,string> every setting, the profile over [Defaults] */
    public function all(): array
    {
        return $this->values;
    }

    /** One setting, the given default when the install does not say it. */
    public function get(string $key, string $default = ''): string
    {
        return trim((string) ($this->values[$key] ?? $default));
    }

    public function etcRoot(): string
    {
        return $this->get('EtcRoot');
    }

    public function coreRoot(): string
    {
        return $this->get('CoreRoot');
    }

    /** The core the install points to (CoreDirectory), empty when it says none. */
    public function coreDirectory(): string
    {
        return $this->get('CoreDirectory');
    }

    /** The host the databases of the install are on (DataSource), empty when it says none. */
    public function dataSource(): string
    {
        return $this->get('DataSource');
    }

    public function varRoot(): string
    {
        return $this->get('VarRoot');
    }

    public function logsRoot(): string
    {
        return $this->get('LogsRoot');
    }

    public function cacheRoot(): string
    {
        return $this->get('CacheRoot');
    }

    public function dataRoot(): string
    {
        return $this->get('DataRoot');
    }

    /** The folder for what the engine writes while running (state, caches): the var root, else the data one. */
    public function stateRoot(): string
    {
        return $this->varRoot() !== ''
            ? $this->varRoot()
            : ($this->dataRoot() !== ''
                ? $this->dataRoot()
                : sys_get_temp_dir());
    }

    /** The user the instances run as, empty when the install has none (they run as whoever starts them). */
    public function systemUser(): string
    {
        return $this->get('SystemUser');
    }

    /** @return list<string> the modules enabled for the regions (EnabledModules, comma or space separated) */
    public function enabledModules(): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', $this->get('EnabledModules')) ?: []));
    }

    /** Where the modules of the install are, by core. */
    public function modulesRoot(): string
    {
        return $this->get('ModulesRoot', '/usr/share/opensim-modules');
    }

    /**
     * The core the instances run from when their grid chooses none: the CoreDirectory of the install when it holds a core, else
     * the latest one under the core root (the releases by version first, then the builds). Null when there is none.
     */
    public function core(): ?Core
    {
        if (!$this->coreFound) {
            $this->coreFound = true;
            $directory = $this->coreDirectory();
            $this->core =
                $directory !== '' && Core::at($directory)->exists()
                    ? Core::at($directory)
                    : Core::latest($this->coreRoot());
        }

        return $this->core;
    }
}
