## Changelog

### Unreleased

### 3.0.0-beta.4

- new: `dev/release.sh` makes the whole release, `dev/switch.sh` the composer part
- new: `dev/build.sh` makes the Debian package and the zip

### 3.0.0-beta.2

- new: `OpenSim_Oar` packs, reads and checks OpenSimulator archives (plain ustar entries)
- update formatting rules
- update dependencies

### 3.0.0-beta.1

First beta of the 3.0 engine, used by opensim-helpers.

- new: `OPENSIM_CONFIG_DIR` sets the config directory, the engine never creates it
- new `opensim_latin1_only()` is in the engine, for the engine and the helpers
- update: PHP 8.2 minimum, `gettext` required
- update: the REST client is the `magicoli/opensim-rest-php` package, not a copy
- update: unused `opensim_filter_text*` disabled, `laminas/laminas-filter` dropped
- update: code formatted from `.editorconfig` and `.prettierrc.json`
- update tests: a pest suite checks the PHP minimum and compatibility
- fix `opensim_sanitize_uri` accepts uppercase hosts and has explicit nullable types
- fix an empty MySQL port is set to its default, which caused fatal errors
