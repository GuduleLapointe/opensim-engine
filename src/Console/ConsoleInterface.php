<?php

declare(strict_types=1);

namespace OpenSim\Console;

/**
 * The console of a running instance: its remote (REST) console, or its screen session.
 */
interface ConsoleInterface
{
    /** Whether the instance can be talked to now. */
    public function available(): bool;

    /** Type a line; an empty line answers a question with its default. False when the instance cannot be reached. */
    public function send(string $line): bool;

    /**
     * Type a line and read what the console answers.
     *
     * @return list<string>|null the lines of the answer, null when the console cannot tell
     */
    public function ask(string $line): ?array;
}
