<?php

namespace OpenSim\Http;

use SimpleXMLElement;

/**
 * The answer of a script that OpenSimulator or a viewer calls: a status line and a text, or the XML-RPC answer the core expects, and the end of the script.
 */
final class Respond
{
    /** @var ?string the key of the request being answered: a same error told twice in a short time is told once */
    public static ?string $requestKey = null;

    /**
     * Die with HTTP error_code, the message being the status line and the text of the answer. Only a failure of the server (5xx) is
     * logged: a request that is wrong, or an address that is not a page (418), is not a failure.
     */
    public static function end($response = null, $error_code = null)
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
            $locale = \OpenSim\Http\Locale::negotiate();
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

    public static function xml($success = true, $error_message = false, $data = false)
    {
        // Data given, output as xmlrpc
        if (is_array($data)) {
            // The same error told twice in a short time is told once (what a consumer that keeps a key per request asks for)
            if (!$success && !empty($error_message) && !empty(self::$requestKey)) {
                # Avoid duplicate error messages
                # TODO: improve to make sure we don't cache error messages for different requests
                #	(currently self::$requestKey only identifies search arguments, client IP and gateway url,
                # 	but client IP is the simulator, not the actual user, so in theory, the key could
                # 	be identical for two simultaneous requests by different users in the same region,
                #	although this is unlikely to happen)

                $tmp_dir = \OpenSim\Support\Tmp::dir();
                $cache = $tmp_dir . '/cache-' . self::$requestKey;
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

    public static function xmlEnd($message = '', $error_code = null)
    {
        if ($error_code !== null && php_sapi_name() !== 'cli' && !headers_sent()) {
            $locale = \OpenSim\Http\Locale::negotiate();
            header('HTTP/1.1 ' . $error_code . ' ' . preg_replace('/\s+/', ' ', trim((string) $message)));
            header('Content-Type: text/xml; charset=utf-8');
            header('Content-Language: ' . $locale);
        }
        // A request that is wrong is the business of who sent it, a server that cannot serve is the business of who runs it
        if ($error_code >= 500 && $message !== '') {
            error_log("[ERROR] $error_code $message");
        }
        self::xml(false, $message, []);
        die();
    }

    /**
     * Mimic OpenSim response for similar bad method calls.
     *
     * Usually for one of these error codes, but any code can be used:
     * - 405: Method Not Allowed
     * - 418: I'm a teapot
     */
    public static function knomes($error_message = false, $error_code = 405)
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
            $locale = \OpenSim\Http\Locale::negotiate();
            header('HTTP/1.1 ' . $error_code . ' ' . $error_message);
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Language: ' . $locale);
        }
        die($error_message);
    }

    /**
     * Flush output and free client so following commands are executed in background
     *
     * @return void
     */
    public static function flush()
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
}
