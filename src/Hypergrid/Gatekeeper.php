<?php

namespace OpenSim\Hypergrid;

/**
 * What a gatekeeper tells of its regions (link_region, get_region), asked by XML-RPC, answers kept for the request.
 */
final class Gatekeeper
{
    /** @var bool whether the certificate of a gatekeeper must be one somebody signed (a grid with its own can say it is not) */
    public static bool $verifyPeer = true;

    /** @var array<string,array<string,mixed>> what the gatekeepers answered during this request, by call and key */
    private static array $cache = [];

    /**
     * [oxXmlRequest description]
     *
     * @param  string $gatekeeper               [description]
     * @param  string $method                   [description]
     * @param  array  $request                  [description]
     * @return array             received xml response
     */
    public static function request($gatekeeper, $method, $request)
    {
        if (!function_exists('xmlrpc_encode_request')) {
            error_log('[ERROR] (XML RPC missing) self::request() requires xmlrpc_encode_request()');
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
        if (!self::$verifyPeer) {
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

    public static function post($requestURL, $request)
    {
        if (empty($request)) {
            return [];
        }
        if (!function_exists('xmlrpc_decode')) {
            // Avoid crash but log error
            error_log('[ERROR] (XML RPC missing) self::post() requires xmlrpc_decode()');
            return false;
        }
        $enable_self_signed = !self::$verifyPeer;

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
    public static function linkRegion($args, $var = null)
    {
        if (empty($args)) {
            error_log('DEBUG ' . __FUNCTION__ . ' empty args');
            return [];
        }
        if (is_string($args)) {
            $region_array = \OpenSim\Hypergrid\Destination::parse($args);
        } elseif (is_array($args)) {
            if (!empty($args['region_uri'])) {
                $region_array = $args;
            } elseif ($args['host'] || $args['region']) {
                $tryurl = join('/', [join(':', [$args['host'] ?? '', $args['port'] ?? '']), $args['region'] ?? '']);

                $region_array = \OpenSim\Hypergrid\Destination::parse($tryurl);
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

        if (isset(self::$cache['link_region'][$region_uri])) {
            $link_region = self::$cache['link_region'][$region_uri];
        } else {
            $link_region = self::request($gatekeeper, 'link_region', [
                'region_name' => $region ?? '',
            ]);
            self::$cache['link_region'][$region_uri] = $link_region;
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
     * Get region data from region name or UUID
     *
     * @param  string|array $region region name or UUID or sanitized region array
     * @param  string $var   optional variable to return
     * @return array
     */
    public static function region($region, $var = null)
    {
        if (empty($region)) {
            return [
                'errorCode' => 400,
                'error' => 'Empty region',
            ];
        }
        if (\OpenSim\Text\Uuid::isValid($region)) {
            // Not implemented, UUID lookup would require a default gatekeeper
            return [
                'errorCode' => 501,
                'error' => 'UUID lookup not implemented',
            ];
        }
        $region = \OpenSim\Hypergrid\Destination::parse($region);
        $link_region = self::linkRegion($region);
        if (!\OpenSim\Text\Uuid::isValid($link_region['uuid'] ?? false)) {
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
        if (isset(self::$cache['get_region'][$uuid])) {
            $get_region = self::$cache['get_region'][$uuid];
        } else {
            $get_region = self::request($gatekeeper, 'get_region', [
                'region_uuid' => "$uuid",
            ]);
            self::$cache['get_region'][$uuid] = $get_region;
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
     * Check if region is online
     *
     * @param  mixed $region   region uri or sanitized region array
     * @return boolean                  true if online
     */
    public static function regionIsOnline($region)
    {
        $link_region = self::linkRegion($region);

        // About `result = "True"` vs `uuid != NULL_KEY`
        //
        // In some circumstances, maybe one of them will be matched
        // and the other will not, while region is found but offline.
        // In doubt, rely on semantics: "result" = "True" means a result
        // was found, not specifically that it's a good result.
        //
        // handle or uuid are better indicators of a good result.

        return (bool) \OpenSim\Text\Uuid::isValid($link_region['uuid'] ?? false);
    }
}
