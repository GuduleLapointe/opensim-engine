<?php
/**
 * helpers/engine/includes/functions.php
 *
 * Provides functions for OpenSim scripts.
 *
 * @package     magicoli/opensim-engine
 * @author      Gudule Lapointe <gudule@speculoos.world>
 * @link            https://github.com/magicoli/opensim-helpers
 * @license     AGPLv3
 */

/**
 * Replace the characters ISO-8859-1 cannot represent by "?".
 *
 * Replaces utf8_encode( utf8_decode( $text ) ), deprecated since PHP 8.2.
 *
 * @param  string $text The text to clean.
 * @return string
 */
function opensim_latin1_only($text)
{
    return mb_convert_encoding(mb_convert_encoding((string) $text, 'ISO-8859-1', 'UTF-8'), 'UTF-8', 'ISO-8859-1');
}

/**
 * Verify if given string is an UUID.
 * In theory, we would check want v4-compliant uuids
 * (xxxxxxxx-xxxx-4xxx-[89AB]xxx-xxxxxxxxxxxx) but OpenSimulator seems to have
 * lot of non v4-compliant uuids left, so stict defaults to false.
 *
 * @param  [type]  $uuid                 string to verify
 * @param  boolean $nullok               accept null value or null key as valid (default false)
 * @param  boolean $strict               apply strict UUID v4 implentation (default false)
 * @return boolean
 */
function is_uuid($uuid, $nullok = false, $strict = false)
{
    // Nothing is the null key's case, accepted when asked; anything else that is not a string is not a UUID
    if ($uuid === null || $uuid === '') {
        return $nullok;
    }
    if (!is_string($uuid)) {
        return false;
    }
    if (defined('NULL_KEY') && $uuid == NULL_KEY) {
        return $nullok;
    }

    if ($strict) {
        return preg_match('/^[0-9A-F]{8}-[0-9A-F]{4}-4[0-9A-F]{3}-[89AB][0-9A-F]{3}-[0-9A-F]{12}$/i', $uuid);
    } else {
        return preg_match('/^[0-9A-F]{8,8}-[0-9A-F]{4,4}-[0-9A-F]{4,4}-[0-9A-F]{4,4}-[0-9A-F]{12,12}$/i', $uuid);
    }
}

/**
 * Sanitize a URL, removing spaces and invalid characters.
 * For any king of URL. For OpenSim teleport links, use opensim_sanitize_uri() instead.
 *
 * @param  string $url  The URL to sanitize.
 * @return string       The sanitized URL.
 */
if (!function_exists('sanitize_url')) {
    // If sanitize_url is not defined, define it
    // This is to avoid conflicts with WordPress or other frameworks that may have their own sanitize_url function
    function sanitize_url($url)
    {
        $url = trim($url);
        $url = str_replace(' ', '+', $url);
        $url = filter_var($url, FILTER_SANITIZE_URL);
        return $url;
    }
}

/**
 * Escape URL for safe output in HTML
 *
 * @param string $url URL to escape
 * @return string Escaped URL
 */
function escape_url($url)
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

/**
 * Does nothing, just returns the string unchanged. Meant to replace dozens of
 * unnecessary calls to htmlspecialchars() in the code.
 */
function do_not_sanitize($string, $foo = '', $bar = '', $john = '', $doe = '')
{
    return $string;
}

function php_has($extension)
{
    switch ($extension) {
        case 'php':
            return true; // PHP is always loaded
        case 'php7':
            return version_compare(PHP_VERSION, '7.0', '>=');
        case 'php7.4':
            return version_compare(PHP_VERSION, '7.4', '>=');
        case 'php8.0':
            return version_compare(PHP_VERSION, '8.0', '>=');
        case 'curl':
            return function_exists('curl_init');
        case 'intl':
            return function_exists('transliterator_transliterate');
        case 'xmlrpc':
            return function_exists('xmlrpc_encode');
        // return function_exists( 'xmlrpc_encode' ) || class_exists( '\\PhpXmlRpc\\Value' );
        case 'imagick':
            return class_exists('Imagick') || class_exists('ImagickDraw') || class_exists('ImagickPixel');
        case 'json':
            return function_exists('json_encode');
        case 'mbstring':
            return function_exists('mb_convert_encoding');
        case 'simplexml':
            return function_exists('simplexml_load_string');
        default:
            // For any other extension, we can use the extension_loaded function
            return extension_loaded($extension);
    }
    return false;
}

/**
 * Sanitize a string to be used as an ID or slug, like for html container id, css class, etc.
 */
function sanitize_id($string)
{
    if (empty($string)) {
        return false;
    }

    $id = $string;
    try {
        // Transliterate to Latin, remove diacritics, lowercase, but keep dashes and underscores
        $id = @transliterator_transliterate('Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Lower();', $id);
    } catch (Error $e) {
        error_log('Warning (php-intl missing) ' . $e->getMessage());
        $id = strtolower($id);
    }
    // Remove all punctuation except dashes and underscores
    $id = preg_replace('/[^\p{L}\p{N}_\-\s]/u', '-', $id);
    // Replace multiple separators with a single one
    $sep = '[-_\s]';
    $id = preg_replace("/$sep* $sep*/", '_', $id);
    $id = preg_replace("/$sep*_$sep*/", '_', $id);
    $id = preg_replace("/$sep*-$sep*/", '-', $id);
    // Remove leading/trailing dashes
    $id = trim($id, '-');

    return $id;
}

function sanitize_version($string)
{
    if (empty($string)) {
        return false;
    }

    $id = $string;
    try {
        // Transliterate to Latin, remove diacritics, lowercase, but keep dashes and underscores
        $id = @transliterator_transliterate('Any-Latin; NFD; [:Nonspacing Mark:] Remove; NFC; Lower();', $id);
    } catch (Error $e) {
        error_log('Warning (php-intl missing) ' . $e->getMessage());
        $id = strtolower($id);
    }
    // Remove all punctuation except dashes and underscores
    $id = preg_replace('/[^\p{L}\p{N}_\-\s\.]/u', '-', $id);
    // Replace multiple separators with a single one
    $sep = '[-_\s]';
    $id = preg_replace("/$sep* $sep*/", '_', $id);
    $id = preg_replace("/$sep*_$sep*/", '_', $id);
    $id = preg_replace("/$sep*-$sep*/", '-', $id);
    // Remove leading/trailing dashes
    $id = trim($id, '-');

    return $id;
}

/**
 * Sanitize a destination URI or URL
 *
 * @param  string  $url                 url or uri or tp link (secondlife:// url, hop:// url, region name...)
 * @param  string  $default_gatekeeper  default login uri to add to urls without host:port, the one of the engine without it
 * @param  boolean $outputArray         output as array
 * @return string|array                 $host:$port $region/$pos, or the array of its parts (host, port, region, pos,
 *                                      gatekeeper, region_uri, dest_uri, key)
 */
function opensim_sanitize_uri($url, $default_gatekeeper = null, $outputArray = false): string|array
{
    if (empty($default_gatekeeper) && class_exists('OpenSim', false) && method_exists('OpenSim', 'login_uri')) {
        $default_gatekeeper = OpenSim::login_uri();
    }
    if ($outputArray) {
        return opensim_parse_url($url, $default_gatekeeper);
    }

    return opensim_uri($url, $default_gatekeeper);
}

/**
 * Format destination uri as a valid local or hypergrid link url
 *
 * @param  string  $destination      Destination uri, as "host:port:Region Name" or already formatted URL
 * @param  integer $format  The desired format as binary flags. Several values can be specified with an addition
 *                          e.g. TPLINK_V3HG + TPLINK_APPTP
 *                          TPLINK_LOCAL or 1:   secondlife://Region Name/x/y/z
 *                          TPLINK_HG or 2:      original HG format (obsolete?)
 *                          TPLINK_V3HG or 4:    v3 HG format (Singularity)
 *                          TPLINK_HOP or 8:     hop:// format (FireStorm)
 *                          TPLINK_TXT or 16:    host:port Region Name
 *                          TPLINK_APPTP or 32:  secondlife:///app/teleport link
 *                          TPLINK_MAP or 64:    (map, not implemented)
 * 							128:                 (for future use, not implemented)
 *                          TPLINK or 255:       output all formats
 *                          TPLINK_ARRAY or 256: output all formats as array
 *
 * @param  string  $sep      Separator for multiple formats, default new line
 * @return string
 */
function opensim_format_tp($destination, $format = null, $sep = "\n"): string|array
{
    if (empty($destination)) {
        return $format & TPLINK_ARRAY ? [] : '';
    }
    // TODO: allow Region, Destination or Event classes
    // if (is_object($destination) && !empty($destination->uri)) {
    // 	$destination = $destination->uri;
    // }
    $format ??= TPLINK_DEFAULT;

    $parsed = opensim_parse_url($destination);
    extract($parsed);

    $post_split = explode('/', $pos ?? '');
    if (count($post_split) >= 2) {
        $pos_x = $post_split[0];
        $pos_y = $post_split[1];

        // set $pos_sl to $pos only if $pos_x and $pos_y < 256
        $pos_sl = $pos_x < 256 && $pos_y < 256 ? $pos : null;
    }

    $region_urlencode = urlencode($region ?? '');
    $region_percentencode = str_replace('+', '%20', $region_urlencode);

    $links = [];
    if (empty($host) && empty($region)) {
        return $format & TPLINK_ARRAY ? [] : '';
    }

    if ($format & TPLINK_TXT) {
        $links[TPLINK_TXT] = trim(
            (empty($host) ? '' : join(':', [$host, $port])) . ' ' . ($region ?? '') . (empty($pos) ? '' : "/$pos"),
        );
    }
    if ($format & TPLINK_LOCAL) {
        // Web only, do not use for in-world messages
        $links[TPLINK_LOCAL] = "secondlife://$region_percentencode" . (empty($pos_sl) ? '' : "/$pos_sl");
    }
    if ($format & TPLINK_HG) {
        // if(empty())
        // Web only, do not use for in-world messages
        $links[TPLINK_HG] = empty($host)
            ? $links[TPLINK_LOCAL]
            : "secondlife://$host:$port%20$region_percentencode" . (empty($pos_sl) ? '' : "/$pos_sl");
    }
    if ($format & TPLINK_V3HG) {
        // Web only, do not use for in-world messages
        $links[TPLINK_V3HG] =
            (empty($host) ? $links[TPLINK_LOCAL] : "secondlife://http|!!$host|$port%20$region_percentencode") .
            (empty($pos_sl) ? '' : "/$pos_sl");
    }
    if ($format & TPLINK_HOP) {
        // Web and in-world. Position (/x/y/z) is
        // - optional for web browser links (viewer applies default)
        // - required for in-world messages
        $links[TPLINK_HOP] = empty($gatekeeper)
            ? ''
            : trim("hop://$host:$port/$region_urlencode", '/') . (empty($pos) ? '' : "/$pos");
    }
    if ($format & TPLINK_APPTP) {
        // In-world messages, do not use for web links
        // secondlife:///app/teleport/speculoos:8002+Grand+Place/" .
        $links[TPLINK_APPTP] =
            (empty($host)
                ? "secondlife:///app/teleport/$region_urlencode/"
                : "secondlife:///app/teleport/$host:$port+$region_urlencode/") . (!empty($pos_sl) ? "$pos_sl/" : '');
    }
    // TODO: Alternative web url when maps implemented in API
    // (No direct map slurl support in the viewer)
    // Example map URLs (TBD in API)
    //  - API_HOST/api/v3/map/$host:$port/$region/128/64/32/
    //  - API_HOST/maps/$host:$port/$region/128/64/32/
    //  - WEB_HOST/maps/$host:$port/$region/128/64/32/
    // if ($format & TPLINK_MAP) {
    // }

    // clean up trailing/leading non-alphanumeric characters
    $links = preg_replace('#^[^[:alnum:]]*|[^[:alnum:]]+$#', '', $links);

    if ($format & TPLINK_ARRAY) {
        return $links;
    }
    return join($sep, $links);
}

/**
 * Use xmlrpc link_region method to request link_region data from robust
 *
 * @param  mixed  $args   region uri or sanitized region array
 * @param  string $var      output a single variable value
 * @return array (or string if var specified)
 * Array format:
 *  - uuid: UUID of the region
 *
 */
function opensim_link_region($args, $var = null)
{
    if (empty($args)) {
        error_log('DEBUG ' . __FUNCTION__ . ' empty args');
        return [];
    }
    global $OSSEARCH_CACHE;

    if (is_string($args)) {
        $region_array = opensim_parse_url($args);
    } elseif (is_array($args)) {
        if (!empty($args['region_uri'])) {
            $region_array = $args;
        } elseif ($args['host'] || $args['region']) {
            $tryurl = join('/', [join(':', [$args['host'] ?? '', $args['port'] ?? '']), $args['region'] ?? '']);

            $region_array = opensim_parse_url($tryurl);
        }
    }
    if (!$region_array) {
        return [
            'success' => false,
            'errorMessage' => 'could not parse region url from args: ' . print_r($args, true),
            'data' => $region_array,
            'args' => $args,
        ];
    }
    extract($region_array); // $host, $port, $region, $pos, $gatekeeper, $region_uri, $dest_uri
    if (empty($gatekeeper)) {
        // TODO: implemeent default gatekeeper and apply if region name is provided alone
        return [
            'success' => false,
            'errorMessage' => 'no gatekeeper',
            'data' => $region_array,
            'args' => $args,
        ];
    }
    if (empty($region_uri)) {
        return [
            'success' => false,
            'errorMessage' => 'could not parse region uri from region_array: ' . print_r($region_array, true),
            'data' => $region_array,
            'args' => $args,
        ];
    }
    $gatekeeper = preg_match('#://#', $gatekeeper) ? $gatekeeper : "http://$gatekeeper";

    if (isset($OSSEARCH_CACHE['link_region'][$region_uri])) {
        $link_region = $OSSEARCH_CACHE['link_region'][$region_uri];
    } else {
        $link_region = oxXmlRequest($gatekeeper, 'link_region', [
            'region_name' => $region ?? '',
        ]);
        $OSSEARCH_CACHE['link_region'][$region_uri] = $link_region;
    }

    if ($link_region) {
        if ($var) {
            return $link_region[$var];
        } else {
            return $link_region;
        }
    }

    return [];
}

/**
 * Build region URL from array
 *
 * @param  array $region sanitized region array
 * @return string
 */
function opensim_region_url($region)
{
    if (!is_array($region)) {
        return false;
    }
    return $region['gatekeeper'] .
        (empty($region['region']) ? '' : ':' . $region['region']) .
        (empty($region['pos']) ? '' : '/' . $region['pos']);
}

/**
 * Get region data from region name or UUID
 *
 * @param  string|array $region region name or UUID or sanitized region array
 * @param  string $var   optional variable to return
 * @return array
 */
function opensim_get_region($region, $var = null)
{
    if (empty($region)) {
        return [
            'errorCode' => 400,
            'error' => 'Empty region',
        ];
    }
    if (is_uuid($region)) {
        // Not implemented, UUID lookup would require a default gatekeeper
        return [
            'errorCode' => 501,
            'error' => 'UUID lookup not implemented',
        ];
    }
    global $OSSEARCH_CACHE;

    $region = opensim_parse_url($region);
    $link_region = opensim_link_region($region);
    if (!is_uuid($link_region['uuid'] ?? false)) {
        return [
            'errorCode' => 400,
            'error' => 'Invalid region UUID',
        ];
    }

    if (empty($region['gatekeeper'])) {
        return [
            'errorCode' => 400,
            'error' => 'Empty gatekeeper',
        ];
    }
    extract($region);
    $gatekeeper = preg_match('#://#', $gatekeeper) ? $gatekeeper : "http://$gatekeeper";

    $uuid = $link_region['uuid'] ?? false;
    if (isset($OSSEARCH_CACHE['get_region'][$uuid])) {
        $get_region = $OSSEARCH_CACHE['get_region'][$uuid];
    } else {
        $get_region = oxXmlRequest($gatekeeper, 'get_region', [
            'region_uuid' => "$uuid",
        ]);
        $OSSEARCH_CACHE['get_region'][$uuid] = $get_region;
    }

    if ($get_region) {
        // What the gatekeeper said of the link, with it
        $get_region['link_region'] = $link_region;
        if ($var) {
            return $get_region[$var];
        } else {
            return $get_region;
        }
    }
    return [];
}

/**
 * Check service availability.
 *
 * Make a fast socket connection to the given host and port.
 *
 * @param  string $host   Hostname or IP address or URL, with or without port
 * @param  int    $port   Port number, optional if included in $host
 * @return boolean        True if the service is available, false otherwise
 */
function service_available($host, $port = null)
{
    if (empty($host)) {
        return false;
    }
    if (strpos($host, ':') !== false) {
        $parts = explode(':', $host);
        $port = array_pop($parts);
        $host = implode(':', $parts);
    }
    if (empty($port) || empty($host)) {
        error_log('[ERROR] service_available() requires a valid host and port');
        return false;
    }

    $fp = @fsockopen($host, $port, $errno, $errstr, 2);
    if (!$fp) {
        return new Exception("Failed to connect to $host:$port - $errstr");
        // return false;
    }

    fclose($fp);
    return true;
}

/**
 * Check if region is online
 *
 * @param  mixed $region   region uri or sanitized region array
 * @return boolean                  true if online
 */
function opensim_region_is_online($region)
{
    $link_region = opensim_link_region($region);

    // About `result = "True"` vs `uuid != NULL_KEY`
    //
    // In some circumstances, maybe one of them will be matched
    // and the other will not, while region is found but offline.
    // In doubt, rely on semantics: "result" = "True" means a result
    // was found, not specifically that it's a good result.
    //
    // handle or uuid are better indicators of a good result.

    return (bool) is_uuid($link_region['uuid'] ?? false);
}

function opensim_user_alert($agentID, $message, $secureID = null)
{
    $agentServer = opensim_get_server_info($agentID);
    if (!$agentServer) {
        return false;
    }
    $serverip = $agentServer['serverIP'];
    $httpport = $agentServer['serverHttpPort'];
    $serveruri = $agentServer['serverURI'];

    $avatarSession = opensim_get_avatar_session($agentID);
    if (!$avatarSession) {
        return false;
    }
    $sessionID = $avatarSession['sessionID'];
    if ($secureID == null) {
        $secureID = $avatarSession['secureID'];
    }

    $request = xmlrpc_encode_request('UserAlert', [
        [
            'clientUUID' => $agentID,
            'clientSessionID' => $sessionID,
            'clientSecureSessionID' => $secureID,
            'Description' => $message,
        ],
    ]);
    $response = currency_xmlrpc_call($serverip, $httpport, $serveruti, $request);

    return $response;
}

/**
 * [oxXmlRequest description]
 *
 * @param  string $gatekeeper               [description]
 * @param  string $method                   [description]
 * @param  array  $request                  [description]
 * @return array             received xml response
 */
function oxXmlRequest($gatekeeper, $method, $request)
{
    if (!function_exists('xmlrpc_encode_request')) {
        error_log('[ERROR] (XML RPC missing) oxXmlRequest() requires xmlrpc_encode_request()');
        return false;
    }
    $xml_request = xmlrpc_encode_request($method, [$request]); // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: text/xml' . "\r\n",
            'timeout' => 3, // most of the time below 1 sec, but leave some time for slow ones
            'content' => $xml_request,
        ],
    ];
    // A grid with a certificate nobody signed, when the settings of the engine say it is fine
    if (class_exists('Engine_Settings') && Engine_Settings::get('engine.Beta.enable_self_signed', false)) {
        $options['ssl'] = ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];
    }
    $context = stream_context_create($options);

    $response = @file_get_contents($gatekeeper, false, $context);
    if ($response === false) {
        return false;
    } elseif (empty($response)) {
        return false;
    }

    // xmlrpc_decode() from library-xmlrpc.php does not handle raw XML strings
    // (new Response($xml) does not parse XML — it is a phpxmlrpc limitation).
    // Use Encoder::decodeXml() directly, which correctly parses any XML-RPC envelope.
    // decodeXml() returns a PhpXmlRpc\Response object; extract the PHP array from it.
    try {
        $encoder = new \PhpXmlRpc\Encoder();
        $decoded = $encoder->decodeXml($response);
    } catch (\Throwable $e) {
        error_log("oxXmlRequest decode error ($gatekeeper $method): " . $e->getMessage());
        return false;
    }
    if (empty($decoded)) {
        return false;
    }
    if ($decoded instanceof \PhpXmlRpc\Response) {
        if ($decoded->faultCode()) {
            error_log(
                "oxXmlRequest fault ($gatekeeper $method): " . $decoded->faultCode() . ' ' . $decoded->faultString(),
            );
            return false;
        }
        $xml_array = $encoder->decode($decoded->value());
    } else {
        $xml_array = $decoded;
    }
    if (empty($xml_array) || !is_array($xml_array)) {
        return false;
    }
    if (xmlrpc_is_fault($xml_array)) {
        // phpcs:ignore PHPCompatibility.FunctionUse.RemovedFunctions
        return false;
    }
    return $xml_array;
}

function get_xml_response_data($requestURL, $request)
{
    if (empty($request)) {
        return [];
    }
    if (!function_exists('xmlrpc_decode')) {
        // Avoid crash but log error
        error_log('[ERROR] (XML RPC missing) get_xml_response_data() requires xmlrpc_decode()');
        return false;
    }
    $enable_self_signed = Engine_Settings::get('engine.Beta.enable_self_signed', false);

    $options = [
        'http' => [
            'method' => 'POST',
            'header' => 'Content-Type: text/xml' . "\r\n",
            'content' => $request,
        ],
    ];

    // Add SSL context options if self-signed certificates should be accepted
    if ($enable_self_signed) {
        $options['ssl'] = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
    }

    $context = stream_context_create($options);
    $response = xmlrpc_decode(file_get_contents($requestURL, false, $context));
    if (is_array($response) && !xmlrpc_is_fault($response) && !empty($response) && isset($response['data'])) {
        return $response['data'];
    } else {
        return [];
    }
}

function fix_utf_encoding($string)
{
    // Ensure the string is in UTF-8 encoding
    if (!mb_check_encoding($string, 'UTF-8')) {
        $string = mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
    }
    return $string;
}

/**
 * Create a standardized response array for consistent project-wide usage
 *
 * @param bool $success Whether the operation was successful
 * @param string $message Human-readable message
 * @param mixed $data Optional data payload (for success responses)
 * @param string $error_code Optional error code for programmatic handling
 * @return array Standardized response structure
 */
function osResponseArray($success, $message, $data = null, $error_code = null)
{
    $response = [
        'success' => (bool) $success,
        'message' => $message,
        'timestamp' => time(),
    ];

    if ($success) {
        if ($data !== null) {
            $response['data'] = $data;
        }
    } else {
        if ($error_code !== null) {
            $response['error_code'] = $error_code;
        }
    }

    return $response;
}

/**
 * Create a success response
 *
 * @param string $message Success message
 * @param mixed $data Optional data payload
 * @return array Success response
 */
function osSuccess($message, $data = null)
{
    return osResponseArray(true, $message, $data);
}

/**
 * Create an error response
 *
 * @param string $message Error message
 * @param int $error_code HTTP response code (also used as exit code)
 * @return array Error response
 */
function osError($message, $error_code = 500)
{
    return osResponseArray(false, $message, null, $error_code);
}

/**
 * Mimic OpenSim response for similar bad method calls.
 *
 * Usually for one of these error codes, but any code can be used:
 * - 405: Method Not Allowed
 * - 418: I'm a teapot
 */
function die_knomes($error_message = false, $error_code = 405)
{
    if (empty($error_message && $error_message !== false)) {
        $error_message = _(
            'The realm of destinations you seek has eluded our grasp, spirited away by elusive knomes. Rally the grid managers, let them venture forth to curate a grand tapestry of remarkable places for your exploration!',
        );
    }

    // Make sure message is UTF-8 encoded
    if (!mb_check_encoding($error_message, 'UTF-8')) {
        $error_message = mb_convert_encoding($error_message, 'UTF-8', 'ISO-8859-1');
    }

    // Add headers not sent yet, including UTF-8 and Content-Language
    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        $locale = set_helpers_locale();
        header('HTTP/1.1 ' . $error_code . ' ' . $error_message);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Language: ' . $locale);
    }
    die($error_message);
}

/**
 * Die with HTTP error_code, the message being the status line and the text of the answer. Only a failure of the server (5xx) is
 * logged: a request that is wrong, or an address that is not a page (418), is not a failure.
 */
function osDie($response = null, $error_code = null)
{
    if (is_array($response)) {
        $success = $response['success'] ?? false;
        $error_code = $response['error_code'] ?? ($success ? 200 : 500);
        $error_message = $response['message'] ?? ($success ? '' : 'Unexpected error occurred ' . $error_code);
    } elseif (is_string($response)) {
        // success if no error_code or error code between 200 and 299
        $success = empty($error_code) || ($error_code >= 200 && $error_code < 300);
        $error_code = $error_code ?? ($success ? 200 : 500);
        $error_message = $response;
    } elseif (is_numeric($response)) {
        $success = empty($error_code) || ($error_code >= 200 && $error_code < 300);
        $error_code = $response ?? ($success ? 200 : 500);
        $error_message = $success ? '' : 'Unexpected error occurred ' . $error_code;
    }

    if (php_sapi_name() !== 'cli' && !headers_sent()) {
        $locale = set_helpers_locale();
        header('HTTP/1.1 ' . $error_code . ' ' . preg_replace('/\s+/', ' ', trim((string) $error_message)));
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Language: ' . $locale);
    }
    // A request that is wrong is the business of who sent it, a server that cannot serve is the business of who runs it
    if (!empty($error_message) && ($success || $error_code >= 500)) {
        error_log($success ? $error_message : "[ERROR] $error_code $error_message");
    }
    die($error_message === null ? '' : $error_message . "\n");
}

function caller_details($level = 2)
{
    // get calling function and file
    $trace = debug_backtrace();
    $level = isset($trace[$level]) ? $level : 1;
    $caller = $trace[$level] ?? $trace[0];

    $file = empty($caller['file']) ? '' : $caller['file'];
    $function = $level > 1 ? $caller['function'] . '()' ?? '' : '';
    $line = $caller['line'] ?? 0;
    $class = $caller['class'] ?? '';
    $type = $caller['type'] ?? '::';
    if ($function && $class) {
        $function = $class . $type . $function;
    }
    $file = $file . ':' . $line;
    return trim(
        sprintf(
            '%s%s %s in %s',
            $function,
            empty($caller['error_code']) ? '' : " Error {$caller['error_code']}",
            $caller['message'] ?? '',
            $file,
        ),
    );
}

function osXmlResponse($success = true, $error_message = false, $data = false)
{
    global $request_key;

    // Data given, output as xmlrpc
    if (is_array($data)) {
        // The same error told twice in a short time is told once (what a consumer that keeps a key per request asks for)
        if (!$success && !empty($error_message) && !empty($request_key)) {
            # Avoid duplicate error messages
            # TODO: improve to make sure we don't cache error messages for different requests
            #	(currently $request_key only identifies search arguments, client IP and gateway url,
            # 	but client IP is the simulator, not the actual user, so in theory, the key could
            # 	be identical for two simultaneous requests by different users in the same region,
            #	although this is unlikely to happen)

            $tmp_dir = get_writable_tmp_dir();
            $cache = $tmp_dir . '/cache-' . $request_key;
            # Check if file exists and is not older than 5 seconds
            if (file_exists($cache) && time() - filemtime($cache) < 1.5) {
                $error_message = '';
            } else {
                file_put_contents($cache, $error_message);
            }
        }

        $array = [
            'success' => $success,
            'errorMessage' => $error_message,
        ];
        // No data to say when it failed and there is none
        if ($success || !empty($data)) {
            $array['data'] = $data;
        }

        $response_xml = xmlrpc_encode($array);

        echo $response_xml;
        return;
    }

    // No data given, output simple boolean or error message, no change here
    if ($success) {
        $answer = new SimpleXMLElement('<boolean>true</boolean>');
    } else {
        $answer = new SimpleXMLElement("<error>$error_message</error>");
    }
    echo $answer->asXML();
}

function osXmlDie($message = '', $error_code = null)
{
    if ($error_code !== null && php_sapi_name() !== 'cli' && !headers_sent()) {
        $locale = set_helpers_locale();
        header('HTTP/1.1 ' . $error_code . ' ' . preg_replace('/\s+/', ' ', trim((string) $message)));
        header('Content-Type: text/xml; charset=utf-8');
        header('Content-Language: ' . $locale);
    }
    // A request that is wrong is the business of who sent it, a server that cannot serve is the business of who runs it
    if ($error_code >= 500 && $message !== '') {
        error_log("[ERROR] $error_code $message");
    }
    osXmlResponse(false, $message, []);
    die();
}

function osNotice($message)
{
    echo $message . "\n";
}

function osAdminNotice($message, $error_code = 0, $die = false)
{
    // get calling function and file
    $trace = debug_backtrace();

    if (isset($trace[1])) {
        $caller = $trace[1];
    } else {
        $caller = $trace[0];
    }
    $file = empty($caller['file']) ? '' : $caller['file'];
    $function = $caller['function'] . '()' ?? 'main';
    $line = $caller['line'] ?? 0;
    $class = $caller['class'] ?? 'main';
    $type = $caller['type'] ?? '::';
    if ($class != 'main') {
        $function = $class . $type . $function;
    }
    $file = $file . ':' . $line;
    $message = sprintf('%s%s: %s in %s', $function, empty($error_code) ? '' : " Error $error_code", $message, $file);
    error_log($message);
    if ($die == true) {
        die($error_code);
    }
}

/**
 * Flush output and free client so following commands are executed in background
 *
 * @return void
 */
function dontWait()
{
    $size = ob_get_length();
    if ($size === null) {
        // No output buffer, nothing to flush
        return;
    }

    header("Content-Length:$size");
    header('Connection:close');
    header('Content-Encoding: none');
    header('Content-Type: text/html; charset=utf-8');

    if ($size) {
        ob_flush();
        ob_end_flush();
    }
    flush();
}

if (!function_exists('osdebug')) {
    function osdebug($message = '')
    {
        if (empty($message)) {
            return;
        }
        if (!is_string($message)) {
            $message = print_r($message, true);
        }
        error_log('[DEBUG] ' . $message);
        echo $message . "\n";
    }
}

function set_helpers_locale($locale = null, $domain = 'messages')
{
    mb_internal_encoding('UTF-8');
    $encoding = mb_internal_encoding();

    if (isset($_GET['l'])) {
        $locale = $_GET['l'];
    }
    $languages = array_filter(array_merge([$locale], explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));

    // $results = putenv("LC_ALL=$locale");
    // if (!$results) {
    // exit ('putenv failed');
    // }

    // $currentLocale = setlocale(LC_ALL, 0);
    $user_locales = array_unique([$locale, $locale . ".$encoding", $locale . '.UTF-8', $locale . '.utf8', $locale, 0]);

    $user_locales = array_map(function ($code) {
        return preg_replace(['/;.*/', '/-/'], ['', '_'], $code);
    }, $languages);

    // Generate variants with different encodings appended
    $variants = [];
    foreach ($user_locales as $lang) {
        $variants[] = $lang;
        $variants[] = "$lang.$encoding";
        // $variants[] = "$lang.UTF-8";
    }

    $variants = array_unique($variants);
    $locale = setlocale(LC_ALL, $variants);
    if (!$locale) {
        // error_log( "setlocale() failed: none of  '" . join( ', ', $variants ) . "' does exist in this environment or setlocale() is not available on this platform" );
        $locale = setlocale(LC_ALL, 0);
        return 0;
    }

    bindtextdomain($domain, './locales');
    textdomain($domain);
    return $locale;
}

function get_writable_tmp_dir()
{
    if (isset($_GLOBALS['tmp_dir'])) {
        return $_GLOBALS['tmp_dir'];
    }
    $dirs = [sys_get_temp_dir(), ini_get('upload_tmp_dir'), '/tmp', '/var/tmp', '/usr/tmp', '.'];
    foreach ($dirs as $dir) {
        if (@is_writable($dir)) {
            $_GLOBALS['tmp_dir'] = $dir;
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

// The constants of the engine, each one only when nobody defined it before (a consumer may have its own)
foreach (
    [
        'NULL_KEY' => '00000000-0000-0000-0000-000000000000',
        'TPLINK_LOCAL' => 1, // secondlife://Region Name/x/y/z
        'TPLINK_HG' => 2, // secondlife://yourgrid.org:8002 Region/x/y/z (original HG format, obsolete?)
        'TPLINK_V3HG' => 4, // the overcomplicated stuff!
        'TPLINK_HOP' => 8, // hop://yourgrid.org:8002:Region/x/y/z
        'TPLINK_TXT' => 16, // yourgrid.org:8002:Region/x/y/z
        'TPLINK_APPTP' => 32, // secondlife:///app/teleport/yourgrid.org:8002:Region/x/y/z
        'TPLINK_MAP' => 64, // secondlife:///app/map/yourgrid.org:8002:Region/x/y/z (not implemented)
        'TPLINK' => 255, // all formats
        'TPLINK_ARRAY' => 256, // output as array
        'HELPERS_LOCALE_DIR' => dirname(__DIR__) . '/languages',
    ]
    as $name => $value
) {
    defined($name) || define($name, $value);
}
defined('TPLINK_DEFAULT') || define('TPLINK_DEFAULT', getenv('TPLINK_DEFAULT') ?: TPLINK_HOP);

/**
 * Safe sprintf function to avoid fatal errors
 * Returns unchanged format string if error occurs
 *
 * @param string $format
 * @param mixed ...$args
 * @return string
 */
function sprintf_safe($format, ...$args)
{
    try {
        $result = sprintf($format, ...$args);
        restore_error_handler();
        return $result;
    } catch (Throwable $e) {
        error_log("Error sprintf_safe( $format, " . join(', ', $args) . '): ' . $e->getMessage());
        restore_error_handler();
        return $format;
    }
}

/**
 * Convert a string to PascalCase.
 * Preserves PascalCase-like words (e.g. "OpenSim", "OSHelper") as they are.
 *
 * @param string $string
 * @return string
 */
function pascal_case($string)
{
    // Convert to PascalCase
    $string = preg_replace('/[^a-zA-Z0-9]+/', ' ', $string); // Replace non-alphanumeric characters with space
    $string = trim(preg_replace('/\s+/', ' ', $string)); // Remove extra spaces
    $words = explode(' ', $string);
    $pascal_words = [];
    foreach ($words as $$key => $word) {
        if (preg_match('/^[A-Z].*[a-z]/', $word)) {
            continue;
        }
        $words[$key] = ucfirst(strtolower($word));
    }
    $string = implode('', $pascal_words);
    return $string;
}

/**
 * parse_ini_file_decode
 * Extended parse_ini_file to handle json_encoded values.
 */
function parse_ini_file_decode($filename, $process_sections = false, $scanner_mode = INI_SCANNER_NORMAL)
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

function isSuccess($response)
{
    if ($response === true || $response === false) {
        return $response;
    }
    if (is_array($response)) {
        return !empty($response['success']) && $response['success'] === true;
    } elseif (is_string($response)) {
        if (in_array(strtolower($response), ['true', 'success', 'ok', 'yes', '1'], true)) {
            return true;
        }
        if (in_array(strtolower($response), ['false', 'error', 'fail', 'no', '0'], true)) {
            return false;
        }
        return !empty($response);
    } elseif (is_numeric($response)) {
        return $response == 1 || ($response >= 200 && $response < 300);
    }
    return false;
}

function isError($response)
{
    return !isSuccess($response);
}

/**
 * OpenSim source to help further attempts to allow Hypergrid search results.
 * Infouuid is a fake parcelid resolving to region handle and (region-level?)
 * pos which might (or not) give enough information to allow hg results.
 * 1. Link region locally with link-region (or directly in db?)
 * 2. Use local link region handle (instead of remote one) to generate infouuid
 * 3. Use local link Global pos instead of remote one
 */
//
// public static UUID BuildFakeParcelID(ulong regionHandle, uint x, uint y)
// {
// byte[] bytes =
// {
// (byte)regionHandle, (byte)(regionHandle >> 8), (byte)(regionHandle >> 16), (byte)(regionHandle >> 24),
// (byte)(regionHandle >> 32), (byte)(regionHandle >> 40), (byte)(regionHandle >> 48), (byte)(regionHandle >> 56),
// (byte)x, (byte)(x >> 8), 0, 0,
// (byte)y, (byte)(y >> 8), 0, 0 };
// return new UUID(bytes, 0);
// }
//
// public static UUID BuildFakeParcelID(ulong regionHandle, uint x, uint y, uint z)
// {
// byte[] bytes =
// {
// (byte)regionHandle, (byte)(regionHandle >> 8), (byte)(regionHandle >> 16), (byte)(regionHandle >> 24),
// (byte)(regionHandle >> 32), (byte)(regionHandle >> 40), (byte)(regionHandle >> 48), (byte)(regionHandle >> 56),
// (byte)x, (byte)(x >> 8), (byte)z, (byte)(z >> 8),
// (byte)y, (byte)(y >> 8), 0, 0 };
// return new UUID(bytes, 0);
// }

require_once __DIR__ . '/functions-escaping.php';

/**
 * Sanitize a destination URI or URL
 *
 * @param  string  $url				url or uri or tp link (secondlife:// url, hop:// url, region name...)
 * @param  string  $default_gatekeeper	default login uri to add to urls sithout host:port
 * @param  boolean $outputArray		output as array
 * @return string       (default)                   $host:$port $region/$pos
 *           or array                                           array($host, $port, $region, $pos)
 */
function opensim_parse_url($url, $default_gatekeeper = null): array
{
    $e_scheme = '(.*://)';
    $e_host = '(([A-Za-z0-9_-]+)(\.[A-Za-z0-9\._-]+)+)';
    $e_short_host = '(([A-Za-z0-9_-]+)(\.[A-Za-z0-9\._-]+)*)?';
    $e_port = '[:\|]([0-9]+)';
    $e_float = '(\d+(\.\d+)?)';

    $normalized_url = preg_replace(
        ["#^{$e_scheme}?{$e_host}{$e_port}?[:\+_ ]#", "#^{$e_scheme}?{$e_short_host}{$e_port}[:\+_ ]#", '/(\+|%20|_)/'],
        ["\\1\\2:\\5/", "\\1\\2:\\5/", ' '],
        urldecode(trim($url)),
    );

    if (!preg_match("#^$e_scheme#", $url) && preg_match("#$e_host|$e_short_host{$e_port}#", $url)) {
        $parsed = parse_url('http://' . $normalized_url) ?: [];
        unset($parsed['scheme']);
    } else {
        $parsed = parse_url($normalized_url) ?: [];
    }

    if (!empty($parsed['host']) || !empty($default_gatekeeper)) {
        $parsed['host'] ??= empty($default_gatekeeper) ? null : parse_url($default_gatekeeper, PHP_URL_HOST) ?? null;
        $parsed['host'] = strtolower(trim($parsed['host']));
        $parsed['port'] ??= parse_url($default_gatekeeper ?? '', PHP_URL_PORT) ?? 80;
        $parsed['gatekeeper'] = 'http://' . $parsed['host'] . ':' . $parsed['port'];
    }

    $split_path = array_values(array_filter(explode('/', $parsed['path'] ?? '')));

    if (count($split_path) > 0) {
        if (!preg_match("/^$e_float$/", $split_path[0])) {
            $parsed['region'] = array_shift($split_path);
        }

        // Sanitize remaining items for position
        $split_path = array_map(
            'floatval',
            array_slice(array_filter($split_path, fn($item) => is_numeric($item)), 0, 3),
        );
        if (count($split_path) >= 2) {
            $parsed['pos'] = implode('/', $split_path);
        }
    }

    if (!empty($parsed['host'])) {
        $parsed['region_uri'] =
            $parsed['host'] . (empty($parsed['host']) || empty($parsed['port']) ? '' : ':' . $parsed['port']);
    }
    $parsed['region_uri'] = trim(
        ($parsed['region_uri'] ?? '') . (empty($parsed['region']) ? '' : '/') . ($parsed['region'] ?? ''),
        ":/ \n\r\t\v\x00",
    );
    $parsed['dest_uri'] = $parsed['region_uri'] . (empty($parsed['pos']) ? '' : "/$parsed[pos]");

    // Every key is always there, for who extracts them; `key` identifies a region in a cache
    $parsed += ['host' => '', 'port' => '', 'region' => '', 'pos' => '', 'gatekeeper' => ''];
    $parsed['key'] = strtolower("$parsed[host]:$parsed[port]/$parsed[region]");

    return $parsed;
}

/**
 * Sanitize a destination URI or URL
 *
 * @param  string  $url				url or uri or tp link (secondlife:// url, hop:// url, region name...)
 * @param  string  $default_gatekeeper	default login uri to add to urls sithout host:port
 * @return string					$host:$port $region/$pos
 */
function opensim_uri($url, $default_gatekeeper = null): string
{
    $parsed = opensim_parse_url($url, $default_gatekeeper);
    return $parsed['dest_uri'] ?? '';
}
