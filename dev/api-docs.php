<?php
/**
 * Makes the documentation of the API from its contract (OpenSim\Api\Contract): the OpenAPI document in JSON and YAML, a page that
 * shows it (index.html, Redoc, loaded from a CDN, the document is in the page: it opens from the disk), and a page to try it
 * (try.html, Swagger UI: the address of the API, the API key to enter with Authorize, and the answers).
 *
 *     composer docs                # docs/api/
 *     php dev/api-docs.php /path   # another folder
 *
 * To write the documentation, edit the descriptions in src/Api/Contract.php, then run it again.
 */

use OpenSim\Api\Contract;

require dirname(__DIR__) . '/vendor/autoload.php';

$out = rtrim($argv[1] ?? dirname(__DIR__) . '/docs/api', '/');
is_dir($out) || mkdir($out, 0o755, true) || exit("cannot make $out\n");

$version = trim((string) @shell_exec('git -C ' . escapeshellarg(dirname(__DIR__)) . ' describe --tags --match "v*" --always 2>/dev/null')) ?: 'dev';
$json = Contract::json($version);
file_put_contents("$out/openapi.json", $json);
file_put_contents("$out/openapi.yaml", Contract::yaml($version));

// The document is in the page, so that it needs no server; "</" cannot end the script that holds it
$spec = str_replace('</', '<\/', $json);
file_put_contents("$out/index.html", <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>OpenSim engine API</title>
    </head>
    <body>
        <p style="margin:0;padding:8px 16px;font:14px sans-serif;background:#f4f4f4"><a href="try.html">Try the API</a> with a key</p>
        <div id="redoc">The documentation needs the Redoc script (cdn.jsdelivr.net), or open openapi.yaml in an editor.</div>
        <script type="application/json" id="spec">$spec</script>
        <script src="https://cdn.jsdelivr.net/npm/redoc@2.1.5/bundles/redoc.standalone.js"></script>
        <script>
            Redoc.init(JSON.parse(document.getElementById('spec').textContent), {}, document.getElementById('redoc'));
        </script>
    </body>
    </html>
    HTML);

// The page to try it: the address of the API is the one of the site that serves the page, else the one to type; the key is entered
// with Authorize and kept by the page
file_put_contents("$out/try.html", <<<HTML
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>OpenSim engine API - try it</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui.css">
    </head>
    <body>
        <p style="margin:0;padding:8px 16px;font:14px sans-serif;background:#f4f4f4">
            <a href="index.html">Documentation</a> &middot;
            <label>Address of the API <input id="server" size="48" placeholder="https://play.example.org/api"></label>
            &middot; the key: Authorize
        </p>
        <div id="ui">Trying the API needs the Swagger UI script (cdn.jsdelivr.net).</div>
        <script type="application/json" id="spec">$spec</script>
        <script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@5.17.14/swagger-ui-bundle.js"></script>
        <script>
            const spec = JSON.parse(document.getElementById('spec').textContent);
            const input = document.getElementById('server');
            let saved = '';
            try { saved = localStorage.getItem('opensim-api-address') || ''; } catch (e) {}
            input.value = saved || (location.protocol.indexOf('http') === 0 ? location.origin + '/api' : '');
            function load() {
                const copy = JSON.parse(JSON.stringify(spec));
                const address = input.value.replace(/\/+$/, '');
                if (address) { copy.servers = [{ url: address, description: 'The API' }]; }
                try { localStorage.setItem('opensim-api-address', input.value); } catch (e) {}
                window.ui = SwaggerUIBundle({ spec: copy, dom_id: '#ui', persistAuthorization: true, tryItOutEnabled: true, deepLinking: false });
            }
            input.addEventListener('change', load);
            load();
        </script>
    </body>
    </html>
    HTML);

echo "$out/index.html\n$out/try.html\n";
