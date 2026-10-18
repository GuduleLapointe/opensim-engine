# OpenSimulator Engine

![Stable](https://img.shields.io/github/release/GuduleLapointe/opensim-engine?label=stable&color=green&include_prerelease)
![GitHub Tag](https://img.shields.io/github/tag/GuduleLapointe/opensim-engine?label=latest&include_prereleases)
![GitHub commits since latest release](https://img.shields.io/github/commits-since/GuduleLapointe/opensim-engine/latest?label=dev)
![PHP](https://img.shields.io/badge/PHP-8.2+-7884bf)
[![License](https://img.shields.io/badge/license-AGPL--3.0-552b55)](LICENSE)
![GitHub Downloads (all assets, all releases)](https://img.shields.io/github/downloads/GuduleLapointe/opensim-engine/total)
[![Donate](https://img.shields.io/badge/-Donate-yellow)](https://magiiic.org/donate/)

**Framework-agnostic PHP library for OpenSimulator grid management**

## ⚠️ Important Notice

**This is a pure PHP library - it does nothing by itself!**

The OpenSimulator Engine provides core functionality for managing OpenSim grids, but requires a parent application to function. It handles database operations, configuration management, and OpenSim protocol communication, but provides no user interface or web endpoints.

## 📜 Project History

This library consolidates **over a decade of OpenSimulator integration work** that was previously scattered across multiple projects. While the dedicated engine repository is recent, the functionality has evolved through years of real-world usage in production OpenSim grids.

The code has been **battle-tested** across different implementations before being organized into this reusable, framework-agnostic library.

## 🎯 What This Library Does

- ✅ **The models of a grid** - grids, simulators, services, avatars, regions, parcels, estates: listed, found, changed, the same way (`docs/data.md`)
- ✅ **The controllers** - Robust and OpenSim processes: start, stop, restart, status, console
- ✅ **Configuration** - the ini files read as OpenSimulator reads them, written without losing what the person added
- ✅ **Databases** - every database asked as itself, wherever the grid keeps it (MySQL, MariaDB)
- ✅ **Provisioning** - planners and provisioners that make a grid, a simulator, a region
- ✅ **Scheduling** - tasks that run by cron or by pseudo cron, locked, with their last run kept
- ✅ **A contract** - resources as JSON:API documents, errors as RFC 9457 problems, an OpenAPI document checked against the models
- ✅ **Security functions, forms, archives (OAR, IAR), health checks**

## 🚫 What This Library Does NOT Do

- ❌ No web interface or HTML pages
- ❌ No user HTTP request handling
- ❌ No user authentication
- ❌ No WordPress or other CMS/framework dependencies

### **For Developers:**

- Use this engine to build your own OpenSim management applications
- Integrate OpenSim functionality into existing PHP projects
- Create custom grid administration tools

## 🚀 Quick Start for End Users

**Don't install this directly!** Instead, choose a complete solution:

- **[W4OS WordPress Plugin](https://github.com/GuduleLapointe/w4os/)** - Complete WordPress integration for OpenSim grids. **Best for:** Complete integration in a WordPress website.
- **[OpenSim Helpers](https://github.com/magicoli/opensim-helpers)** - Provides mainly helpers/ required by OpenSim grids to function properly, as well as minimal webui features. **Best for:** Separate helpers management, with minimal integration with the website.

## Requirements

PHP 8.2 or newer, with the `curl`, `filter`, `intl`, `json`, `mbstring`, `mysqli`, `pdo`, `session` and `simplexml` extensions, and `gettext` to translate the messages (without it they stay in English: `includes/functions-gettext.php` stands in for its functions). The `xmlrpc_*` functions removed from PHP 8 are not an extension to install: the engine uses them, and loads a polyfill (`includes/library-xmlrpc.php`, built on `phpxmlrpc/phpxmlrpc`) when the extension is missing.

On Debian and Ubuntu: `sudo apt install php-cli php-curl php-intl php-mbstring php-mysql php-xml`, the other extensions come with `php-common` and `php-cli`.

## The machine and its models

Everything the engine does on a machine goes through an `Environment`: where the install is (`Layout`), its processes, its launcher, its consoles, its clock. A front-end makes one, a test makes one with fakes.

```php
use OpenSim\Config\Layout;
use OpenSim\Models\{Grid, Simulator, Service};
use OpenSim\Runtime\Environment;

$environment = Environment::system(Layout::resolve());      // the install of this machine
$grid = Grid::find($environment, 'vonda');
$grid->robust()->isRunning();                                // its Robust (a controller)
$grid->service('asset')->uri();                              // a service of the grid, and who serves it
$simulator = $grid->simulator('welcome');
$simulator->service('profile')->uri();                       // the service as this simulator reaches it
$grid->start(simulators: true);
```

A grid has one or several Robust instances, each serving some of its services; a standalone grid has none, its simulator serves everything. The simulators inherit the services of their grid.

| Namespace | What it is |
| --- | --- |
| `OpenSim\Models` | `Grid`, `Simulator`, `Service` (of the machine), `Avatar`, `Region`, `Parcel`, `Estate` (of a grid's databases) |
| `OpenSim\Controllers` | `RobustController`, `OpenSimController`: the processes that serve |
| `OpenSim\Runtime` | `Environment`, `Core` (an OpenSimulator release), the process table, the launcher |
| `OpenSim\Config` | `Layout` (opensim.conf), `GridConfig` (the files of a grid), `ConfigReader`, `IniEditor` |
| `OpenSim\Data` | `Database` (the databases of a grid), `Connections`, `OSPDO`, `Collection` |
| `OpenSim\Provisioning` | planners and provisioners: they answer "what would this be" before "make it" |
| `OpenSim\Scheduling` | `Scheduler`, `Task`: cron and pseudo cron |
| `OpenSim\Api` | `Api`, `Contract`, `Problem`: the resources as JSON:API, the errors as RFC 9457 |
| `OpenSim\Archives`, `Health`, `Hypergrid`, `Network`, `Security`, `Forms`, `Testing` | archives, health checks, hypergrid links, ports, escaping, forms, fakes |

`OpenSim\Testing\Machine` is a machine that is a folder (a grid `demo`, two simulators), `EnvironmentFake::with($layout)` an environment that touches nothing: the suite runs anywhere (`composer test`).

## 🛠️ Developer Installation

### As Composer Package

```bash
composer require magicoli/opensim-engine
```

### As Git Submodule

```bash
git submodule add https://github.com/magicoli/opensim-engine.git engine
```

### Usage in Code

```php
require 'vendor/autoload.php';   // PSR-4: OpenSim\ is src/; the gettext stand-in and the xmlrpc polyfill load by themselves
```

## 📚 Documentation

- **[Developer Guide](DEVELOPERS.md)** - Architecture rules and patterns
- **[The data of a grid](docs/data.md)** - the models (avatars, regions, parcels), the collections and the databases
- **[Migration of the consumers](docs/MIGRATION.md)** - what was renamed, moved or removed

## 🤝 Contributing

This library follows strict architectural principles:

- Framework-agnostic (no WordPress, no Laravel, etc.)
- Pure data processing (no HTTP input/output)
- Explicit data passing (no global variables)

See [DEVELOPERS.md](DEVELOPERS.md) for complete guidelines.


## 📄 License

AGPLv3 - See [LICENSE](LICENSE) file for details.

## 🆘 Support

- **End Users:** Get support from the project using this engine (W4OS, Helpers)
- **Developers:** Create issues for bugs or feature requests https://github.com/magicoli/opensim-engine/issues
- **Documentation:** Check the parent project documentation first
