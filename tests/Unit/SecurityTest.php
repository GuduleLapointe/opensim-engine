<?php

use OpenSim\Security\Escape;
use OpenSim\Security\Sanitize;

describe('what goes out', function () {
    test('is escaped for the place it goes to', function () {
        expect(Escape::html('<b>"Tom" & Jerry</b>'))->not->toContain('<b>')->toContain('&amp;')
            ->and(Escape::attr('" onmouseover="alert(1)'))->not->toContain('" onmouseover')
            ->and(Escape::js("x'; alert(1); //"))->not->toContain("'")
            ->and(Escape::url('a b&c=d'))->toBe('a%20b%26c%3Dd')
            ->and(Escape::css('a;b{c}'))->not->toContain('{');
    });
});

describe('what comes in', function () {
    test('is filtered to the simple value it should be', function () {
        expect(Sanitize::key('My Key_1!'))->toBe('mykey_1')
            ->and(Sanitize::int('12abc3'))->toBe('123')
            ->and(Sanitize::float('1.5x'))->toBe('1.5')
            ->and(Sanitize::email('a b@example.org'))->toBe('ab@example.org')
            ->and(Sanitize::url(' http://x.org/a b '))->toBe('http://x.org/a+b');
    });

    test('has the tags it allows and the attributes that run code taken out', function () {
        $html = '<p onclick="x()" style="color:red">Hi <script>alert(1)</script><a href="/y">y</a></p>';

        expect(Sanitize::html($html))->toBe('<p>Hi alert(1)<a href="/y">y</a></p>')
            ->and(Sanitize::basicHtml('<h1>t</h1><b>b</b>'))->toBe('t<b>b</b>')
            ->and(Sanitize::richText('<h1>t</h1><table>x</table>'))->toBe('<h1>t</h1>x');
    });
});
