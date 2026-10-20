<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

/**
 * What a plan collected, as the variables the presets put in their files (see OptionTemplate): each property by its name in
 * snake_case (`baseHostname` is `{{base_hostname}}`, a boolean is `true` or `false`, a list is its items between commas), and what
 * the plan derives from them.
 */
trait PlanVariables
{
    /** @return array<string,string> */
    public function variables(): array
    {
        $values = [];
        foreach (get_object_vars($this) as $name => $value) {
            $text = match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                is_int($value), is_float($value), is_string($value) => (string) $value,
                is_array($value) && array_is_list($value) && count(array_filter($value, 'is_scalar')) === count($value) => implode(
                    ', ',
                    $value,
                ),
                default => null,
            };
            $text === null || ($values[strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $name))] = $text);
        }

        return $this->derived() + $values;
    }

    /** @return array<string,string> what the plan works out from what it collected */
    abstract protected function derived(): array;
}
