<?php
/**
 * The configuration of an OpenSimulator environment, by its old name: the class is `OpenSim\Config\GridConfig`, the reading of the
 * ini files `OpenSim\Config\ConfigReader`. This keeps the names and the methods the helpers and the plugins use.
 *
 * @package magicoli/opensim-engine
 * @license AGPLv3
 * @deprecated use OpenSim\Config\GridConfig and OpenSim\Config\ConfigReader.
 */

use OpenSim\Config\ConfigReader;
use OpenSim\Config\GridConfig;

class OpenSim_Config extends GridConfig
{
    /**
     * The config of a grid folder.
     *
     * @param  string               $dir
     * @param  array<string,string> $extra role => file name
     * @return static
     */
    public static function from_folder($dir, array $extra = [])
    {
        return static::fromFolder($dir, $extra);
    }

    /**
     * An ini file the way OpenSimulator reads it, without the files it includes, the constants replaced.
     *
     * @param  string               $path
     * @param  array<string,string> $constants
     * @return array<string,array<string,string>> section => key => value, empty when unreadable
     */
    public static function read_ini($path, $constants = [])
    {
        return ConfigReader::expand(ConfigReader::file((string) $path), $constants);
    }

    /**
     * A file and the files it includes, the constants replaced.
     *
     * @param  string               $path
     * @param  array<string,string> $constants those of another file (Robust's), for the ones the chain has not
     * @return array<string,array<string,string>> section => key => value, empty when unreadable
     */
    public static function read($path, $constants = [])
    {
        return ConfigReader::load((string) $path, $constants);
    }
}
