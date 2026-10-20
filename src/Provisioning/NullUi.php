<?php

namespace OpenSim\Provisioning;

/**
 * An interface that asks nothing: the answers are the proposals. For the planning of a setup that nobody sees. What is said is kept,
 * by level (note, warn, error), for whoever wants to tell it.
 */
final class NullUi implements UiInterface
{
    /** @var list<array{0:string,1:string}> level, message */
    public array $said = [];

    /** @param bool $live the notes are also told on the error output as they come (a setup whose client shows its progress) */
    public function __construct(private readonly bool $live = false) {}

    public function intro(string $title): void {}

    public function note(string $message): void
    {
        $this->said[] = ['note', $message];
        $this->live && fwrite(STDERR, "opensim: $message\n");
    }

    public function entity(string $name): string
    {
        return $name;
    }

    public function warn(string $message): void
    {
        $this->said[] = ['warn', $message];
    }

    public function error(string $message): void
    {
        $this->said[] = ['error', $message];
    }

    public function outro(string $message): void {}

    public function choose(string $label, array $options, ?string $default = null, ?string $hint = null): string
    {
        return $default ?? (string) array_key_first($options);
    }

    public function checklist(string $label, array $options, array $defaults = [], ?string $hint = null): array
    {
        return $defaults;
    }

    public function text(string $label, string $default = '', ?\Closure $validate = null, ?string $hint = null): string
    {
        return $default;
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
        return $default;
    }

    public function spin(\Closure $callback, string $message): mixed
    {
        return $callback();
    }
}
