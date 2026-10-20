<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Runtime\PendingReporterInterface;

/**
 * What the engine says while it starts or stops an instance, told through the interface of the setup, and kept to be read
 * (whether an instance is "still starting" is in what was said).
 */
final class UiReporter implements PendingReporterInterface
{
    /** @var list<string> */
    private array $said = [];

    public function __construct(private readonly UiInterface $ui) {}

    public function info(string $message): void
    {
        $this->said[] = $message;
        $this->ui->note($message);
    }

    public function error(string $message): void
    {
        $this->said[] = $message;
        $this->ui->error($message);
    }

    public function debug(string $message): void {}

    /** A step of the wait (a service loaded, a region ready): told as it comes, one line each. */
    public function step(string $text): void
    {
        $this->ui->note($text);
    }

    /** What is being waited for: the message starts with `~`, the client replaces it with the next one. */
    public function pending(string $text): void
    {
        $this->ui->note('~' . $text);
    }

    public function stepEnd(): void {}

    public function isTerminal(): bool
    {
        return false;
    }

    /** Everything that was said, one line each. */
    public function said(): string
    {
        return implode("\n", $this->said);
    }
}
