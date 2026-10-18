<?php

/**
 * What PHP no longer has everywhere: the xmlrpc_* functions come from the library where the extension is missing (the library file
 * declares them as soon as it is compiled, which is why it is loaded here, after the check, and not by the autoloader).
 */
if (!function_exists('xmlrpc_encode')) {
    require_once __DIR__ . '/library-xmlrpc.php';
}
