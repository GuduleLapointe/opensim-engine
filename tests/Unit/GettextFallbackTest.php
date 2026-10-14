<?php

declare(strict_types=1);

/**
 * The gettext functions of includes/functions-gettext.php.
 */

/** Run code in a PHP whose gettext functions are disabled, with the autoloader of the engine. */
function gettext_fallback_run(string $code): string
{
    $functions = '_,gettext,ngettext,bindtextdomain,textdomain,bind_textdomain_codeset';
    $script = 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . '; ' . $code;

    return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d disable_functions=' . $functions . ' -r ' . escapeshellarg($script) . ' 2>&1');
}

describe('Without the gettext extension', function () {
    test('a message comes back as it is', function () {
        expect(gettext_fallback_run('echo function_exists("_") ? _("Region %s is online.") : "no function";'))->toBe('Region %s is online.');
    });

    test('a plural follows the count', function () {
        expect(gettext_fallback_run('echo ngettext("%d region", "%d regions", 1), "|", ngettext("%d region", "%d regions", 0), "|", ngettext("%d region", "%d regions", 5);'))
            ->toBe('%d region|%d regions|%d regions');
    });

    test('the domain is set up as the extension answers', function () {
        expect(gettext_fallback_run('echo bindtextdomain("opensim-kit", "/locales"), "|", bind_textdomain_codeset("opensim-kit", "UTF-8"), "|", textdomain("opensim-kit");'))
            ->toBe('/locales|UTF-8|opensim-kit');
    });
});

test('With the extension, its own functions are the ones used', function () {
    expect((new ReflectionFunction('_'))->isInternal())->toBeTrue();
})->skip(!extension_loaded('gettext'), 'ext-gettext is not loaded');
