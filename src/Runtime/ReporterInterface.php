<?php

declare(strict_types=1);

namespace OpenSim\Runtime;

/**
 * What the engine says while it works: the front-end shows it (a command line prints it, the administration keeps it).
 */
interface ReporterInterface
{
    /** What is done: "starting vonda", "vonda started". */
    public function info(string $message): void;

    /** A problem: the instance that did not start, the reason. */
    public function error(string $message): void;

    /** What the operator asks to see to find out why (the command that is run, the files used). */
    public function debug(string $message): void;

    /**
     * One line of progress. On a terminal each one takes the place of the previous, which makes a quiet line of what takes a
     * while; elsewhere each is a line of its own, once. `stepEnd()` closes the line left open.
     */
    public function step(string $text): void;

    /** End the line of progress left open, so that what comes next starts on its own. */
    public function stepEnd(): void;

    /** Whether the progress can be shown in place (a terminal), and a long wait counted second by second. */
    public function isTerminal(): bool;
}
