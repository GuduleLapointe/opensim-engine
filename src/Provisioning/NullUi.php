<?php

namespace OpenSim\Provisioning;

/**
 * An interface that says nothing and asks nothing: the answers are the proposals. For the planning of a setup that nobody sees.
 */
final class NullUi implements UiInterface
{
    public function intro(string $title): void {}

    public function note(string $message): void {}

    public function entity(string $name): string
    {
        return $name;
    }

    public function warn(string $message): void {}

    public function error(string $message): void {}

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
