<?php

declare(strict_types=1);

namespace OpenSim\Api;

/**
 * What the old scripts of the helpers took, given to the standard endpoints, so that a script is a few lines and the signs, the boards
 * and the objects of a grid that call it keep working (they are in the hundreds, in world: they are not changed).
 *
 *     // textgen.php
 *     $response = Legacy::textgen($api, $_REQUEST);
 *
 * The other scripts keep their parameters and their format: `register.php`, `query.php`, `offline.php`, `currency.php` and
 * `landtool.php` are fixed by OpenSimulator and the viewers, `motd.php`, `parser.php` and `eventsparser.php` take none.
 */
final class Legacy
{
    /**
     * The request of `textgen.php`, answered by the standard text image: the picture, or the list of the fonts (`json` and `list`).
     * The text is decoded a second time, as the script did (the objects that call it send it that way), and `\'` is `'`.
     *
     * @param array<string,mixed> $request `string`, `font`, `size` or `fontsize`, `color` or `textcolor`, `background` or `backgroundcolor`, `download`, `inpage`, `json`, `list`
     */
    public static function textgen(Api $api, array $request): Response
    {
        if (isset($request['json'])) {
            return new Response(200, ['Content-Type' => 'application/json'], $api->fontNames());
        }
        if (isset($request['list'])) {
            return Response::binary(implode("\n", $api->fontNames()), 'text/plain; charset=utf-8');
        }

        return $api->textImage(self::textImageQuery($request));
    }

    /**
     * The standard query of a text image from the parameters of `textgen.php`.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed>
     */
    public static function textImageQuery(array $request): array
    {
        $query = [
            'font' => $request['font'] ?? 'Impact',
            'size' => $request['size'] ?? ($request['fontsize'] ?? 120),
            'color' => $request['color'] ?? ($request['textcolor'] ?? 'white'),
            'background' => $request['background'] ?? ($request['backgroundcolor'] ?? 'black'),
            // The picture is offered as a file unless the page asks it in the page (`inpage`, which came after `download`)
            'download' => isset($request['download']) && !isset($request['inpage']),
        ];
        if (isset($request['string'])) {
            $query['text'] = urldecode(str_replace("\\'", "'", (string) $request['string']));
        }

        return $query;
    }
}
