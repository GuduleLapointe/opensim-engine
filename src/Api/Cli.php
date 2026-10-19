<?php

declare(strict_types=1);

namespace OpenSim\Api;

/**
 * The API on the command line: the words typed are a request, what comes back is printed. The same addresses and the same answers
 * as the web, without a server.
 *
 *     opensim api GET /grids/demo/monitor?format=text
 *     opensim api GET /grids filter[hypergrid]=yes sort=-name
 *     opensim api PATCH /simulators/alpha enabled=false
 *     opensim api POST /grids/demo/avatars name="Ada Love" password=secret
 *     opensim api POST /grids/demo/search/query --data=@call.xml
 *
 * The words after the path are the arguments of the address (`key=value`, `filter[x]=y`), or the attributes of the resource for a POST
 * and a PATCH. `--data=` gives the body as it is: JSON, `@file`, or `-` for the standard input. The answer is the JSON document
 * (pretty) or the body as it is (text, XML, a picture); the status is 0 for a success, 1 for a problem, 2 for a command that is not
 * understood.
 */
final class Cli
{
    public const USAGE = "usage: opensim api [GET|POST|PATCH|DELETE] PATH [key=value...] [--data=JSON|@FILE|-]\n";

    public function __construct(private readonly Router $router) {}

    /**
     * @param list<string> $args what follows `api`
     * @param ?\Closure():string $stdin the standard input, for `--data=-`
     * @return array{0:int,1:string,2:string} the status, what is printed, what is said on the error output
     */
    public function run(array $args, ?\Closure $stdin = null): array
    {
        $method = 'GET';
        if (isset($args[0]) && in_array(strtoupper($args[0]), ['GET', 'POST', 'PATCH', 'DELETE'], true)) {
            $method = strtoupper((string) array_shift($args));
        }
        $address = (string) array_shift($args);
        if ($address === '' || $address[0] !== '/' || in_array('--help', $args, true)) {
            return [$address === '' || in_array('--help', $args, true) ? 0 : 2, '', self::USAGE];
        }
        $body = null;
        $words = [];
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--data=')) {
                $data = substr($arg, 7);
                $body =
                    $data === '-'
                        ? ($stdin
                            ? $stdin()
                            : '')
                        : (str_starts_with($data, '@')
                            ? self::file(substr($data, 1))
                            : $data);
                if ($body === null) {
                    return [2, '', "api: cannot read the file {$data}\n"];
                }
            } elseif (str_starts_with($arg, '-')) {
                return [2, '', "api: unknown option $arg\n" . self::USAGE];
            } else {
                $words[] = $arg;
            }
        }

        // The address may carry its own query
        $path = (string) parse_url($address, PHP_URL_PATH);
        parse_str((string) parse_url($address, PHP_URL_QUERY), $query);
        $given = [];
        parse_str(implode('&', array_map(static fn(string $word): string => self::encode($word), $words)), $given);

        if (in_array($method, ['POST', 'PATCH'], true)) {
            if ($body === null && $given !== []) {
                $body = json_encode(
                    ['data' => ['type' => self::type($path), 'attributes' => array_map(self::value(...), $given)]],
                    JSON_THROW_ON_ERROR,
                );
            }
        } else {
            $query = $given + $query;
        }

        $answer = $this->router->route($method, $path, $query, $body ?? '');

        return [$answer->status >= 400 ? 1 : 0, self::print($answer), self::problem($answer)];
    }

    /** The kind of resource an address is about. */
    private static function type(string $path): string
    {
        $segments = array_values(array_filter(explode('/', $path), 'strlen'));

        return ($segments[0] ?? '') === 'grids' && count($segments) >= 3 ? $segments[2] : $segments[0] ?? '';
    }

    /** `key=value`, the key kept as it is (brackets included) and the value encoded. */
    private static function encode(string $word): string
    {
        [$key, $value] = array_pad(explode('=', $word, 2), 2, '');

        return rawurlencode($key) . '=' . rawurlencode($value);
    }

    /** A value typed: true, false and null are what they say, numbers are numbers. */
    private static function value(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        return match (true) {
            $value === 'true' => true,
            $value === 'false' => false,
            $value === 'null' => null,
            is_numeric($value) && preg_match('/^-?(?:0|[1-9]\d*)(?:\.\d+)?$/', $value) === 1 => $value + 0,
            default => $value,
        };
    }

    private static function file(string $path): ?string
    {
        $text = is_file($path) ? file_get_contents($path) : false;

        return $text === false ? null : $text;
    }

    private static function print(Response $answer): string
    {
        if ($answer->status === 204) {
            return '';
        }
        $json = $answer->document !== [] && str_contains($answer->headers['Content-Type'] ?? '', 'json');
        $text = $json
            ? json_encode(
                $answer->document,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            )
            : $answer->body();

        return $text === '' || str_ends_with($text, "\n") ? $text : $text . "\n";
    }

    /** What a problem says, on the error output. */
    private static function problem(Response $answer): string
    {
        if ($answer->status < 400) {
            return '';
        }
        $document = $answer->document;
        $said = trim(($document['title'] ?? '') . ': ' . ($document['detail'] ?? ''), ': ');

        return "HTTP {$answer->status}" . ($said !== '' ? " $said" : '') . "\n";
    }
}
