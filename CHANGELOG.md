## Changelog

### 3.0.0-beta.2

- new `OpenSim_Oar` works with OpenSimulator archives: `pack` makes one from a folder laid out as its content (plain ustar entries, which OpenSimulator reads, not the extended ones the tar of macOS adds), `entries`, `info`, `check` and `unpack` read them (control file, number of objects, parcels and assets, XML that does not parse, entries out of their folder)
- feat(oar): OpenSim_Oar makes, reads and checks OpenSimulator archives
- docs: use with the OpenSim kit
- update formatting rules
- update dependencies

Requires review (engine should not depend on the Kit nor be aware of it):

- new `OpenSim_Kit` reads what the OpenSim kit knows of a grid, for the projects that run next to it: the profile of `opensim.conf`, the Robust config of the grid (constants expanded) and its `helpers.ini`; it gives the settings of the helpers, the constants their scripts expect, and the public path of each service, which `helpers.ini` can change (`[Urls] search = "/search"`)
- feat(kit): motd service for the helpers
- feat(kit): helpers.ini can carry the web url and the Robust database, the helpers need nothing else
- feat(kit): OpenSim_Kit is autoloaded by composer
- feat(kit): read the profile, the Robust config and the helpers.ini of a grid of the OpenSim kit

### 3.0.0-beta.1

First beta of the 3.0 engine, used by opensim-helpers and the OpenSim kit.

- new `OPENSIM_CONFIG_DIR` sets the config directory; the engine never creates it by itself, `create_config_directory()` is the recipe for the setup of the application
- new `opensim_latin1_only()` is in the engine, for the engine and the helpers
- update PHP 8.2 is the minimum (composer platform 8.2.0), the code runs clean on PHP 8.2 to 8.5; the `gettext` extension is required
- update the REST client is the `magicoli/opensim-rest-php` package, not an embedded copy; the XML-RPC polyfill is loaded by opensim-helpers, not by the engine
- update `opensim_filter_text` and `opensim_filter_textarea`, unused, are disabled and `laminas/laminas-filter` is not a dependency anymore; `laminas/laminas-escaper` is a stable `^2.12`
- update the code is formatted from `.editorconfig` and `.prettierrc.json` (single quotes, PSR-12)
- update tests: a pest suite checks the PHP minimum and compatibility
- fix `opensim_sanitize_uri` accepts uppercase hosts and has explicit nullable types
- fix an empty MySQL port is set to its default, which caused fatal errors
