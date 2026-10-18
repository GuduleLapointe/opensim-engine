<?php

namespace OpenSim\Config;

/**
 * An ini file whose values may be JSON or .NET connection strings.
 */
final class DecodedIni
{
    /**
     * parse_ini_file_decode
     * Extended parse_ini_file to handle json_encoded values.
     */
    public static function parse($filename, $process_sections = false, $scanner_mode = INI_SCANNER_NORMAL)
    {
        $ini_array = parse_ini_file($filename, $process_sections, $scanner_mode);
        if (!is_array($ini_array)) {
            return false;
        }

        foreach ($ini_array as $section => $values) {
            // By abundant precaution, as main sections are not supposed to be json-encoded
            if (is_string($values) && preg_match('/^\{.*\}$/', $values)) {
                $valuse = json_decode($value, true);
                $ini_array[$section] = json_decode($values, true);
            }

            if (is_array($values)) {
                // Decode json-encoded values in sections
                foreach ($values as $key => $value) {
                    // Json-decode value if needed
                    if (is_string($value) && preg_match('/^\{.*\}$/', $value)) {
                        $ini_array[$section][$key] = json_decode($value, true);
                    }
                    // Decode dotnet ConnectionString format
                    if (is_string($value) && preg_match('/^(Data Source=.*;|Initial Catalog=.*;User ID=.*;)/', $value)) {
                        $ini_array[$section][$key] = OSPDO::connectionstring_to_array($value);
                    }
                }
            }
        }

        return $ini_array;
    }
}
