<?php

/**
 * The constants the scripts of OpenSimulator and the helpers use as global ones, each one only when nobody defined it before (a
 * consumer may have its own). The values are the engine's: the formats of the links are those of OpenSim\Hypergrid\Destination.
 */

use OpenSim\Hypergrid\Destination;

foreach (
    [
        'NULL_KEY' => '00000000-0000-0000-0000-000000000000',
        'TPLINK_LOCAL' => Destination::LOCAL,
        'TPLINK_HG' => Destination::HG,
        'TPLINK_V3HG' => Destination::V3HG,
        'TPLINK_HOP' => Destination::HOP,
        'TPLINK_TXT' => Destination::TXT,
        'TPLINK_APPTP' => Destination::APPTP,
        'TPLINK_MAP' => Destination::MAP,
        'TPLINK' => Destination::ALL,
        'TPLINK_ARRAY' => Destination::AS_ARRAY,
        'HELPERS_LOCALE_DIR' => dirname(__DIR__) . '/languages',
    ]
    as $name => $value
) {
    defined($name) || define($name, $value);
}
defined('TPLINK_DEFAULT') || define('TPLINK_DEFAULT', Destination::defaultFormat());
