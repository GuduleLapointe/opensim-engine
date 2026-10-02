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

- ✅ **Database Operations** - Robust/OpenSim database management
- ✅ **OpenSim Protocol** - REST API communication with grids
- ✅ **Configuration Management** - Grid and region settings management and storage
- ✅ **Security Functions** - Input validation and output escaping
- ✅ **Form Generation** - Dynamic configuration forms
- ✅ **Installation** - Process setup automation (initiated by parent)

## 🚫 What This Library Does NOT Do

- ❌ No web interface or HTML pages
- ❌ No user HTTP request handling
- ❌ No user authentication
- ❌ No WordPress or other CMS/framework dependencies
- ❌ No standalone application functionality

### **For Developers:**

- Use this engine to build your own OpenSim management applications
- Integrate OpenSim functionality into existing PHP projects
- Create custom grid administration tools

## 🚀 Quick Start for End Users

**Don't install this directly!** Instead, choose a complete solution:

- **[W4OS WordPress Plugin](https://github.com/GuduleLapointe/w4os/)** - Complete WordPress integration for OpenSim grids. **Best for:** Complete integration in a WordPress website.
- **[OpenSim Helpers](https://github.com/magicoli/opensim-helpers)** - Provides mainly helpers/ required by OpenSim grids to function properly, as well as minimal webui features. **Best for:** Separate helpers management, with minimal integration with the website.

## Requirements

PHP 8.2 or newer, with the `curl`, `filter`, `gettext`, `intl`, `json`, `mbstring`, `mysqli`, `pdo`, `session` and `simplexml` extensions. The `xmlrpc_*` functions removed from PHP 8 are not an extension to install: the engine uses them, and the application loads a polyfill (`includes/xmlrpc-polyfill.php` of opensim-helpers, built on `phpxmlrpc/phpxmlrpc`).

On Debian and Ubuntu: `sudo apt install php-cli php-curl php-intl php-mbstring php-mysql php-xml`, the other extensions come with `php-common` and `php-cli`.

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
// Bootstrap the engine
require_once 'engine/bootstrap.php';
// or require_once 'vendor/magicoli/opensim-engine/bootstrap.php' if installed via composer

// Engine provides the core classes and functions
// See parent projects (W4OS, Helpers) for implementation examples
```

## 📚 Documentation

- **[Developer Guide](DEVELOPERS.md)** - Architecture rules and patterns
- **API Reference** - (planned after migration completion)
- **Examples** - (planned after migration completion)

## 🤝 Contributing

This library follows strict architectural principles:

- Framework-agnostic (no WordPress, no Laravel, etc.)
- Pure data processing (no HTTP input/output)
- Explicit data passing (no global variables)

See [DEVELOPERS.md](DEVELOPERS.md) for complete guidelines.

**Note:** The library is undergoing architectural migration. API documentation and examples will be added once the refactoring is complete and the API is stable.

## 📄 License

AGPLv3 - See [LICENSE](LICENSE) file for details.

## 🆘 Support

- **End Users:** Get support from the project using this engine (W4OS, Helpers)
- **Developers:** Create issues for bugs or feature requests https://github.com/magicoli/opensim-engine/issues
- **Documentation:** Check the parent project documentation first
