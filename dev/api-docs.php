<?php
/**
 * Makes the documentation of the API from its contract (OpenSim\Api\Contract): the OpenAPI document in JSON and YAML, and a page
 * that shows it (Redoc, loaded from a CDN, the document is in the page: it opens from the disk).
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
        <div id="redoc">The documentation needs the Redoc script (cdn.jsdelivr.net), or open openapi.yaml in an editor.</div>
        <script type="application/json" id="spec">$spec</script>
        <script src="https://cdn.jsdelivr.net/npm/redoc@2.1.5/bundles/redoc.standalone.js"></script>
        <script>
            Redoc.init(JSON.parse(document.getElementById('spec').textContent), {}, document.getElementById('redoc'));
        </script>
    </body>
    </html>
    HTML);

echo "$out/index.html\n";
