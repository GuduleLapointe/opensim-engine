<?php

namespace OpenSim\Hypergrid;

/**
 * A destination: a region and a position, as people write them (host:port:Region/x/y/z, hop://, secondlife://...), parsed into the parts, written back as the one canonical uri, or as the links each viewer takes.
 */
final class Destination
{
    /** secondlife://Region Name/x/y/z */
    public const LOCAL = 1;
    /** secondlife://yourgrid.org:8002 Region/x/y/z (original HG format, obsolete?) */
    public const HG = 2;
    /** the overcomplicated stuff! */
    public const V3HG = 4;
    /** hop://yourgrid.org:8002:Region/x/y/z */
    public const HOP = 8;
    /** yourgrid.org:8002:Region/x/y/z */
    public const TXT = 16;
    /** secondlife:///app/teleport/yourgrid.org:8002:Region/x/y/z */
    public const APPTP = 32;
    /** secondlife:///app/map/yourgrid.org:8002:Region/x/y/z (not implemented) */
    public const MAP = 64;
    /** all formats */
    public const ALL = 255;
    /** output as array */
    public const AS_ARRAY = 256;

    /** The format asked when none is: the environment says (TPLINK_DEFAULT), else hop://. */
    public static function defaultFormat(): int
    {
        return (int) (getenv('self::defaultFormat()') ?: self::HOP);
    }

    /**
     * Sanitize a destination URI or URL
     *
     * @param  string  $url				url or uri or tp link (secondlife:// url, hop:// url, region name...)
     * @param  string  $default_gatekeeper	default login uri to add to urls sithout host:port
     * @param  boolean $outputArray		output as array
     * @return string       (default)                   $host:$port $region/$pos
     *           or array                                           array($host, $port, $region, $pos)
     */
    public static function parse($url, $default_gatekeeper = null): array
    {
        $e_scheme = '(.*://)';
        $e_host = '(([A-Za-z0-9_-]+)(\.[A-Za-z0-9\._-]+)+)';
        $e_short_host = '(([A-Za-z0-9_-]+)(\.[A-Za-z0-9\._-]+)*)?';
        $e_port = '[:\|]([0-9]+)';
        $e_float = '(\d+(\.\d+)?)';

        $normalized_url = preg_replace(
            [
                "#^{$e_scheme}?{$e_host}{$e_port}?[:\+_ ]#",
                "#^{$e_scheme}?{$e_short_host}{$e_port}[:\+_ ]#",
                '/(\+|%20|_)/',
            ],
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
            $parsed['host'] ??= empty($default_gatekeeper)
                ? null
                : parse_url($default_gatekeeper, PHP_URL_HOST) ?? null;
            $parsed['host'] = strtolower(trim($parsed['host']));
            $parsed['port'] ??= parse_url($default_gatekeeper ?? '', PHP_URL_PORT) ?? 80;
            $parsed['gatekeeper'] = 'http://' . $parsed['host'] . ':' . $parsed['port'];
        }

        // A part that is "0" is a part (a position at the edge of a region), only an empty one is not
        $split_path = array_values(array_filter(explode('/', $parsed['path'] ?? ''), fn($part) => $part !== ''));

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
    public static function uri($url, $default_gatekeeper = null): string
    {
        $parsed = self::parse($url, $default_gatekeeper);
        return $parsed['dest_uri'] ?? '';
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
    public static function sanitize($url, $default_gatekeeper = null, $outputArray = false): string|array
    {
        if ($outputArray) {
            return self::parse($url, $default_gatekeeper);
        }

        return self::uri($url, $default_gatekeeper);
    }

    /**
     * The login address of a grid as its people write it (`play.example.org`, `http://play.example.org:8002/`) in its parts. The
     * scheme is `http` and the port 8002 when none is given (OSGrid answers on 80).
     *
     * @return ?array{scheme:string,host:string,port:int} null when it is no address
     */
    public static function loginParts(string $address): ?array
    {
        $address = trim($address);
        if ($address === '') {
            return null;
        }
        $address = preg_match('#^https?://#i', $address) === 1 ? $address : 'http://' . $address;
        $parts = parse_url($address);
        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        return [
            'scheme' => strtolower($parts['scheme'] ?? 'http'),
            'host' => $parts['host'],
            'port' => (int) ($parts['port'] ?? (preg_match('/osgrid\.org/i', $address) === 1 ? 80 : 8002)),
        ];
    }

    /** The login URL of a grid, `scheme://host:port`, from the address its people write; null when it is no address. */
    public static function loginUrl(string $address): ?string
    {
        $parts = self::loginParts($address);

        return $parts === null ? null : "{$parts['scheme']}://{$parts['host']}:{$parts['port']}";
    }

    /**
     * A link with its spaces as pluses, for the formats that take neither `%20` nor `_` (`host:port+Region+Name`), the characters
     * a URL cannot have removed.
     */
    public static function sanitizePlus(string $url): string
    {
        return (string) filter_var(str_replace(' ', '+', trim($url)), FILTER_SANITIZE_URL);
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
    public static function format($destination, $format = null, $sep = "\n"): string|array
    {
        if (empty($destination)) {
            return $format & self::AS_ARRAY ? [] : '';
        }
        // TODO: allow Region, Destination or Event classes
        // if (is_object($destination) && !empty($destination->uri)) {
        // 	$destination = $destination->uri;
        // }
        $format ??= self::defaultFormat();

        $parsed = self::parse($destination);
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
            return $format & self::AS_ARRAY ? [] : '';
        }

        if ($format & self::TXT) {
            $links[self::TXT] = trim(
                (empty($host) ? '' : join(':', [$host, $port])) . ' ' . ($region ?? '') . (empty($pos) ? '' : "/$pos"),
            );
        }
        if ($format & self::LOCAL) {
            // Web only, do not use for in-world messages
            $links[self::LOCAL] = "secondlife://$region_percentencode" . (empty($pos_sl) ? '' : "/$pos_sl");
        }
        if ($format & self::HG) {
            // if(empty())
            // Web only, do not use for in-world messages
            $links[self::HG] = empty($host)
                ? $links[self::LOCAL]
                : "secondlife://$host:$port%20$region_percentencode" . (empty($pos_sl) ? '' : "/$pos_sl");
        }
        if ($format & self::V3HG) {
            // Web only, do not use for in-world messages
            $links[self::V3HG] =
                (empty($host) ? $links[self::LOCAL] : "secondlife://http|!!$host|$port%20$region_percentencode") .
                (empty($pos_sl) ? '' : "/$pos_sl");
        }
        if ($format & self::HOP) {
            // Web and in-world. Position (/x/y/z) is
            // - optional for web browser links (viewer applies default)
            // - required for in-world messages
            $links[self::HOP] = empty($gatekeeper)
                ? ''
                : trim("hop://$host:$port/$region_urlencode", '/') . (empty($pos) ? '' : "/$pos");
        }
        if ($format & self::APPTP) {
            // In-world messages, do not use for web links
            // secondlife:///app/teleport/speculoos:8002+Grand+Place/" .
            $links[self::APPTP] =
                (empty($host)
                    ? "secondlife:///app/teleport/$region_urlencode/"
                    : "secondlife:///app/teleport/$host:$port+$region_urlencode/") .
                (!empty($pos_sl) ? "$pos_sl/" : '');
        }
        // TODO: Alternative web url when maps implemented in API
        // (No direct map slurl support in the viewer)
        // Example map URLs (TBD in API)
        //  - API_HOST/api/v3/map/$host:$port/$region/128/64/32/
        //  - API_HOST/maps/$host:$port/$region/128/64/32/
        //  - WEB_HOST/maps/$host:$port/$region/128/64/32/
        // if ($format & self::MAP) {
        // }

        // clean up trailing/leading non-alphanumeric characters
        $links = preg_replace('#^[^[:alnum:]]*|[^[:alnum:]]+$#', '', $links);

        if ($format & self::AS_ARRAY) {
            return $links;
        }
        return join($sep, $links);
    }

    /**
     * Build region URL from array
     *
     * @param  array $region sanitized region array
     * @return string
     */
    public static function regionUrl($region)
    {
        if (!is_array($region)) {
            return false;
        }
        return $region['gatekeeper'] .
            (empty($region['region']) ? '' : ':' . $region['region']) .
            (empty($region['pos']) ? '' : '/' . $region['pos']);
    }
}
