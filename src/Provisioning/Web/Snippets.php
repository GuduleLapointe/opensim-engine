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

    /**
     * Render the web-server include for a grid from its shipped preset.
     *
     * @throws \InvalidArgumentException when the server has no preset
     * @throws \RuntimeException when the preset file cannot be read
     */
    public static function render(
        string $server,
        string $nick,
        string $gridDirectory,
        string $docroot = '',
        string $socket = '',
        string $webUrl = '',
    ): string {
        $server = strtolower(trim($server));
        if (!in_array($server, self::SERVERS, true)) {
            throw new \InvalidArgumentException("unknown web server '$server'");
        }

        $preset = ((new Presets())->options()["webserver-$server"] ?? null)?->directory();
        $templates = glob(($preset ?? '') . '/web/*') ?: [];
        if ($preset === null || $templates === []) {
            throw new \RuntimeException("web-server preset '$server' is not installed");
        }
        $template = $templates[0];
        $text = file_get_contents($template);
        if ($text === false) {
            throw new \RuntimeException("cannot read web-server preset $template");
        }

        $webUrl = $webUrl !== '' ? $webUrl : HelpersConfig::webUrl($gridDirectory);
        $services = HelpersConfig::services($gridDirectory);
        $segments = [];
        foreach ([...array_keys(Services::SCRIPTS), ...array_keys(Services::PAGES)] as $page) {
            $segment = explode('/', trim($services->path($page), '/'))[0];
            $segment === '' || ($segments[$segment] = true);
        }
        $segments['health'] = true;
        $variables = self::siteVariables($webUrl) + [
            'grid_nick' => $nick,
            'grid_dir' => rtrim($gridDirectory, '/'),
            'docroot' => $docroot !== '' ? $docroot : self::WEBROOT . '/html',
            'php_socket' => $socket !== '' ? $socket : '/run/php/php-fpm.sock',
            'web_globs' => implode(' ', array_map(static fn(string $path): string => "/$path*", array_keys($segments))),
            'web_regex' => implode('|', array_map('preg_quote', array_keys($segments))),
        ];

        return (string) preg_replace_callback(
            '/{{([a-z_]+)}}/',
            static fn(array $match): string => $variables[$match[1]] ?? $match[0],
            $text,
        );
    }
}
