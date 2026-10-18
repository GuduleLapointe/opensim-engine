<?php

namespace OpenSim\Support;

/**
 * A folder the script can write in.
 */
final class Tmp
{
    private static ?string $dir = null;

    public static function dir()
    {
        if (self::$dir !== null) {
            return self::$dir;
        }
        $dirs = [sys_get_temp_dir(), ini_get('upload_tmp_dir'), '/tmp', '/var/tmp', '/usr/tmp', '.'];
        foreach ($dirs as $dir) {
            if (@is_writable($dir)) {
                self::$dir = $dir;
                return $dir;
            }
        }
        error_log(
            __FILE__ .
                ':' .
                __LINE__ .
                ' ERROR - could not find a writable temporary directory, check web server and PHP config',
        );
        return false;
        // return '/tmp';
    }
}
