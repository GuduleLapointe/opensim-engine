<?php

declare(strict_types=1);

namespace OpenSim\Testing;

use OpenSim\Console\ScreenInterface;

/**
 * The console of an instance that answers what a test says.
 */
final class ConsoleFake implements ScreenInterface
{
    /** @var list<string> */
    public array $sent = [];

    /** @var array<string,list<string>> what a line is answered */
    public array $answers = [];

    /** @var ?\Closure(string):void what happens when a line is sent */
    public ?\Closure $onSend = null;

    public bool $available = true;

    /** @var list<string> what the screen shows, one text per reading, the last one kept */
    public array $screens = [];
    public bool $session = true;

    public function available(): bool
    {
        return $this->available;
    }

    public function send(string $line): bool
    {
        $this->sent[] = $line;
        $this->onSend !== null && ($this->onSend)($line);

        return $this->available;
    }

    public function ask(string $line): ?array
    {
        $this->send($line);

        return $this->available ? $this->answers[$line] ?? [] : null;
    }

    public function exists(): bool
    {
        return $this->session;
    }

    public function screenText(): string
    {
        return count($this->screens) > 1 ? (string) array_shift($this->screens) : (string) ($this->screens[0] ?? '');
    }

    public function attach(): int
    {
        return 0;
    }
}
