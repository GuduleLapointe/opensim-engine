## Changelog

### 3.0.0-beta.2

- new `OpenSim_Oar` works with OpenSimulator archives: `pack` makes one from a folder laid out as its content (plain ustar entries, which OpenSimulator reads, not the extended ones the tar of macOS adds), `entries`, `info`, `check` and `unpack` read them (control file, number of objects, parcels and assets, XML that does not parse, entries out of their folder)
- feat(oar): OpenSim_Oar makes, reads and checks OpenSimulator archives
- update formatting rules
- update dependencies

### 3.0.0-beta.1

First beta of the 3.0 engine, used by opensim-helpers.

- new `OPENSIM_CONFIG_DIR` sets the config directory; the engine never creates it by itself, `create_config_directory()` is the recipe for the setup of the application
- new `opensim_latin1_only()` is in the engine, for the engine and the helpers
- update PHP 8.2 is the minimum (composer platform 8.2.0), the code runs clean on PHP 8.2 to 8.5; the `gettext` extension is required
- update the REST client is the `magicoli/opensim-rest-php` package, not an embedded copy; the XML-RPC polyfill is loaded by opensim-helpers, not by the engine
- update `opensim_filter_text` and `opensim_filter_textarea`, unused, are disabled and `laminas/laminas-filter` is not a dependency anymore; `laminas/laminas-escaper` is a stable `^2.12`
- update the code is formatted from `.editorconfig` and `.prettierrc.json` (single quotes, PSR-12)
- update tests: a pest suite checks the PHP minimum and compatibility
- fix `opensim_sanitize_uri` accepts uppercase hosts and has explicit nullable types
- fix an empty MySQL port is set to its default, which caused fatal errors
