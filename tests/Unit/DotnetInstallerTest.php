<?php

declare(strict_types=1);

/**
 * Installing what OpenSimulator needs to run: the .NET runtime and libgdiplus, from the system packages, a runtime installed
 * elsewhere, or the install script of Microsoft. The machine is a script of answers.
 */

use OpenSim\Provisioning\UiInterface;
use OpenSim\Runtime\DotnetInstaller;

/** An interface that answers its questions from a list and keeps what it was told and asked. */
final class DotnetUiFake implements UiInterface
{
    /** @var list<string> */
    public array $said = [];
    /** @var list<string> */
    public array $asked = [];

    /** @param array<string,string|bool> $answers question (start of it) => answer */
    public function __construct(private array $answers = []) {}

    public function intro(string $title): void {}
    public function note(string $message): void
    {
        $this->said[] = $message;
    }
    public function entity(string $name): string
    {
        return $name;
    }
    public function warn(string $message): void
    {
        $this->said[] = "warn: $message";
    }
    public function error(string $message): void
    {
        $this->said[] = "error: $message";
    }
    public function outro(string $message): void {}
    public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string
    {
        return $default ?? '';
    }
    public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array
    {
        return $defaults;
    }
    public function text(string $label, string $default = '', ?\Closure $validate = null, ?string $hint = null): string
    {
        $this->asked[] = $label;

        return (string) $this->answer($label, $default);
    }
    public function secret(string $label, ?\Closure $validate = null, ?string $hint = null): string
    {
        return '';
    }
    public function form(array $fields, string $title = ''): array
    {
        return [];
    }
    public function confirm(string $label, bool $default = true): bool
    {
        $this->asked[] = $label;

        return (bool) $this->answer($label, $default);
    }
    public function spin(\Closure $callback, string $message): mixed
    {
        return $callback();
    }

    private function answer(string $label, string|bool $default): string|bool
    {
        foreach ($this->answers as $start => $answer) {
            if (str_starts_with($label, $start)) {
                return $answer;
            }
        }

        return $default;
    }
}

/**
 * A machine: what each command prints (the first pattern that matches the command), and the commands that were run.
 *
 * @param array<string,string> $outputs pattern => output of a capture
 * @param array<string,int> $statuses pattern => status of a run (0 for the others)
 * @return array{0:\Closure,1:\Closure,2:\ArrayObject}
 */
function dotnet_machine(array $outputs = [], array $statuses = []): array
{
    $ran = new ArrayObject();
    $capture = static function (string $command) use ($outputs): string {
        foreach ($outputs as $pattern => $output) {
            if (preg_match($pattern, $command)) {
                return $output;
            }
        }

        return '';
    };
    $run = static function (string $command) use ($statuses, $ran): int {
        $ran[] = $command;
        foreach ($statuses as $pattern => $status) {
            if (preg_match($pattern, $command)) {
                return $status;
            }
        }

        return 0;
    };

    return [$capture, $run, $ran];
}

/** The tools of a machine with apt and curl, libgdiplus present. */
function dotnet_tools(array $more = []): array
{
    return $more + [
        '/command -v .apt-cache./' => '/usr/bin/apt-cache',
        '/command -v .apt-get./' => '/usr/bin/apt-get',
        '/command -v .curl./' => '/usr/bin/curl',
        '/ldconfig -p/' => "libgdiplus.so.0 (libc6,x86-64) => /usr/lib/libgdiplus.so.0\nlibicuuc.so.72 (libc6)",
    ];
}

describe('.NET runtime', function () {
    test('is left alone when there is one that fits, a newer one included', function () {
        [$capture, $run, $ran] = dotnet_machine(
            dotnet_tools([
                '/command -v .dotnet./' => '/usr/bin/dotnet',
                '/dotnet.* --list-runtimes/' => "Microsoft.NETCore.App 9.0.1 [/usr/share/dotnet/shared]\n",
            ]),
        );
        $ui = new DotnetUiFake();

        expect((new DotnetInstaller($ui, $capture, $run, true))->install(8))
            ->toBeTrue()
            ->and($ran->getArrayCopy())
            ->toBe([])
            ->and($ui->said[0])
            ->toContain('.NET 9 found');
    });

    test('is installed from the system packages when they have it, as root', function () {
        [$capture, $run, $ran] = dotnet_machine(
            dotnet_tools([
                '/apt-cache policy .dotnet-runtime-8.0./' =>
                    "dotnet-runtime-8.0:\n  Installed: (none)\n  Candidate: 8.0.4-1\n",
            ]),
        );

        expect((new DotnetInstaller(new DotnetUiFake(), $capture, $run, true))->install(8))
            ->toBeTrue()
            ->and($ran->getArrayCopy())
            ->toBe(['apt-get install -y dotnet-runtime-8.0']);
    });

    test('uses a newer system runtime package when it satisfies the requested major', function () {
        [$capture, $run, $ran] = dotnet_machine(
            dotnet_tools([
                '/apt-cache pkgnames dotnet-runtime-/' => "dotnet-runtime-10.0\n",
                '/apt-cache policy .dotnet-runtime-10.0./' =>
                    "dotnet-runtime-10.0:\n  Installed: (none)\n  Candidate: 10.0.1-1\n",
            ]),
        );

        expect((new DotnetInstaller(new DotnetUiFake(), $capture, $run, true))->install(8))
            ->toBeTrue()
            ->and($ran->getArrayCopy())
            ->toBe(['apt-get install -y dotnet-runtime-10.0']);
    });

    test('is installed by a person who is not root only as far as telling them how', function () {
        [$capture, $run, $ran] = dotnet_machine(
            dotnet_tools(['/apt-cache policy .dotnet-runtime-8.0./' => "dotnet-runtime-8.0:\n  Candidate: 8.0.4-1\n"]),
        );
        $ui = new DotnetUiFake();

        expect((new DotnetInstaller($ui, $capture, $run, false))->install(8))
            ->toBeFalse()
            ->and($ran->getArrayCopy())
            ->toBe([])
            ->and($ui->said)
            ->toContain('error: Install it as root: sudo apt install dotnet-runtime-8.0');
    });

    test('says when the system packages fail', function () {
        [$capture, $run] = dotnet_machine(
            dotnet_tools(['/apt-cache policy .dotnet-runtime-8.0./' => "  Candidate: 8.0.4-1\n"]),
            ['/apt-get install/' => 100],
        );
        $ui = new DotnetUiFake();

        expect((new DotnetInstaller($ui, $capture, $run, true))->install(8))
            ->toBeFalse()
            ->and($ui->said)
            ->toContain('error: Could not install dotnet-runtime-8.0');
    });

    test(
        'is installed with the script of Microsoft when the system has no package, in a folder for everybody as root, then linked',
        function () {
            $links = sys_get_temp_dir() . '/dotnet-links-' . bin2hex(random_bytes(4));
            mkdir($links);
            [$capture, $run, $ran] = dotnet_machine(
                dotnet_tools([
                    '/apt-cache policy .dotnet-runtime-8.0./' => "dotnet-runtime-8.0:\n  Candidate: (none)\n",
                    '/apt-cache pkgnames/' => "libicu72\nlibicu-dev\nlibicu67\n",
                    '/ldconfig -p/' => "libgdiplus.so.0 (libc6)\n",
                ]),
            );

            $ok = (new DotnetInstaller(new DotnetUiFake(), $capture, $run, true, 'Linux', '', $links))->install(8);

            $commands = $ran->getArrayCopy();
            expect($ok)
                ->toBeTrue()
                ->and($commands[0])
                ->toBe("apt-get install -y 'libicu72'")
                ->and($commands[1])
                ->toStartWith('curl -fsSL https://builds.dotnet.microsoft.com/dotnet/scripts/v1/dotnet-install.sh -o ')
                ->and($commands[2])
                ->toContain("--runtime dotnet --channel '8.0' --install-dir '/usr/local/share/dotnet'")
                ->and(readlink("$links/dotnet"))
                ->toBe('/usr/local/share/dotnet/dotnet');
        },
    );

    test('is installed in the home of a person who is not root, and the PATH is told', function () {
        [$capture, $run, $ran] = dotnet_machine(dotnet_tools());
        $ui = new DotnetUiFake();

        $ok = (new DotnetInstaller($ui, $capture, $run, false, 'Linux', '/home/jane'))->install(8);

        expect($ok)
            ->toBeTrue()
            ->and($ran[1])
            ->toContain("--install-dir '/home/jane/.dotnet'")
            ->and(implode("\n", $ui->said))
            ->toContain('export PATH="/home/jane/.dotnet:$PATH"');
    });

    test(
        'is not installed when the person refuses the script, nor when curl is missing, nor when the download fails',
        function () {
            [$capture, $run, $ran] = dotnet_machine(dotnet_tools());
            $ui = new DotnetUiFake(['Install the .NET 8 runtime' => false]);
            expect((new DotnetInstaller($ui, $capture, $run, false, 'Linux', '/home/jane'))->install(8))
                ->toBeFalse()
                ->and($ran->getArrayCopy())
                ->toBe([]);

            [$capture, $run] = dotnet_machine(dotnet_tools(['/command -v .curl./' => '']));
            $ui = new DotnetUiFake();
            expect((new DotnetInstaller($ui, $capture, $run, false, 'Linux', '/home/jane'))->install(8))
                ->toBeFalse()
                ->and($ui->said)
                ->toContain('error: curl is needed to download the install script');

            [$capture, $run] = dotnet_machine(dotnet_tools(), ['/curl -fsSL/' => 22]);
            $ui = new DotnetUiFake();
            expect((new DotnetInstaller($ui, $capture, $run, false, 'Linux', '/home/jane'))->install(8))
                ->toBeFalse()
                ->and($ui->said)
                ->toContain('error: Could not download dotnet-install.sh');
        },
    );

    test('is the one a person has installed elsewhere, linked, when they say where', function () {
        $dir = sys_get_temp_dir() . '/dotnet-elsewhere-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents("$dir/dotnet", "#!/bin/sh\n");
        chmod("$dir/dotnet", 0o755);
        $links = "$dir/links";
        mkdir($links);
        [$capture, $run, $ran] = dotnet_machine(
            dotnet_tools(['/dotnet.* --list-runtimes/' => "Microsoft.NETCore.App 8.0.4 [/x]\n"]),
        );

        // The first look at the PATH finds nothing: the output of this dotnet is only given for the one of the person
        $capture = static fn(string $command): string => str_contains($command, $dir)
            ? "Microsoft.NETCore.App 8.0.4 [/x]\n"
            : dotnet_machine(dotnet_tools())[0]($command);
        $ok = (new DotnetInstaller(
            new DotnetUiFake(['Path of an installed dotnet' => $dir]),
            $capture,
            $run,
            true,
            'Linux',
            '',
            $links,
        ))->install(8);

        expect($ok)
            ->toBeTrue()
            ->and($ran->getArrayCopy())
            ->toBe([])
            ->and(readlink("$links/dotnet"))
            ->toBe(realpath("$dir/dotnet"));
    });
});

describe('libgdiplus', function () {
    test('is installed from the system packages as root, for the regions stop at once without it', function () {
        [$capture, $run, $ran] = dotnet_machine(
            dotnet_tools([
                '/ldconfig -p/' => 'libicuuc.so.72',
                '/apt-cache policy .libgdiplus./' => "libgdiplus:\n  Candidate: 6.1-1\n",
                '/command -v .dotnet./' => '/usr/bin/dotnet',
                '/dotnet.* --list-runtimes/' => "Microsoft.NETCore.App 8.0.4 [/x]\n",
            ]),
        );

        (new DotnetInstaller(new DotnetUiFake(), $capture, $run, true, 'Linux'))->install(8);

        expect($ran->getArrayCopy())->toBe(['apt-get install -y libgdiplus']);
    });

    test('is only asked for by a person who is not root', function () {
        [$capture, $run, $ran] = dotnet_machine(
            dotnet_tools([
                '/ldconfig -p/' => 'libicuuc.so.72',
                '/command -v .dotnet./' => '/usr/bin/dotnet',
                '/dotnet.* --list-runtimes/' => "Microsoft.NETCore.App 8.0.4 [/x]\n",
            ]),
        );
        $ui = new DotnetUiFake();

        (new DotnetInstaller($ui, $capture, $run, false, 'Linux'))->install(8);

        expect($ran->getArrayCopy())
            ->toBe([])
            ->and($ui->said[0])
            ->toContain('warn: OpenSim regions also need libgdiplus');
    });
});
