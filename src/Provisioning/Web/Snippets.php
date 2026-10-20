<?php

namespace OpenSim\Provisioning\Web;

use OpenSim\Provisioning\Presets;

/**
 * What a web server needs to serve the site of a grid: the document root of the site, where what exists is
 * served as it is and everything else goes to `index.php`, the router of the helpers (see the helpers), PHP run
 * for it, the grid named to the helpers (OPENSIM_GRID).
 *
 * Caddy, nginx and Apache are written, to include in the site of the grid, not to replace its config. The files are presets
 * (`webserver-caddy`, `webserver-nginx`, `webserver-apache`, see OptionTemplate): this only gives them the site of the grid.
 */
final class Snippets
{
    public const SERVERS = ['caddy', 'nginx', 'apache'];

    /** Where the helpers are installed. */
    public const WEBROOT = '/usr/share/opensim-helpers';

    /**
     * The web server of this machine, null when none is found.
     *
     * @return ?array{name:string,label:string,config:string,suffix:string,directive:string}
     */
    public static function detect(): ?array
    {
        $servers = [
            ['caddy', 'Caddy', '/etc/caddy/Caddyfile', '.caddyfile', 'import %s', ['/etc/caddy', '/usr/bin/caddy', '/usr/local/bin/caddy']],
            ['nginx', 'nginx', '/etc/nginx/sites-enabled', '-nginx.conf', 'include %s;', ['/etc/nginx', '/usr/sbin/nginx', '/usr/local/bin/nginx']],
            ['apache', 'Apache', '/etc/apache2/sites-enabled', '-apache.conf', 'Include %s', ['/etc/apache2', '/etc/httpd', '/usr/sbin/apache2']],
        ];
        foreach ($servers as [$name, $label, $config, $suffix, $directive, $marks]) {
            foreach ($marks as $mark) {
                if (file_exists($mark)) {
                    return compact('name', 'label', 'config', 'suffix', 'directive');
                }
            }
        }

        return null;
    }

    /**
     * Where the site answers, from its web URL, as the files of a web server say it: the host (none: any), the scheme, the port when
     * it is not the usual one of its scheme, the address of the site as Caddy writes it, the port a server listens on.
     *
     * @return array<string,string>
     */
    public static function siteVariables(string $webUrl): array
    {
        $parts = parse_url(trim($webUrl)) ?: [];
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        $port = $port === ($scheme === 'http' ? 80 : 443) ? null : $port;
        $host = (string) ($parts['host'] ?? '');
        $scheme = $scheme === 'http' ? 'http' : 'https';
        $explicit = $port !== null ? ":$port" : '';

        return [
            'web_url' => trim($webUrl),
            'web_host' => $host,
            'web_scheme' => $scheme,
            'web_port' => $port === null ? '' : (string) $port,
            'site_address' => match (true) {
                $host === '' => ':' . ($port ?? 80),
                $scheme === 'http' => "http://$host$explicit",
                default => $host . $explicit,
            },
            'listen_port' => (string) ($scheme === 'http' ? $port ?? 80 : 80),
        ];
    }
}
