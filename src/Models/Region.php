<?php

declare(strict_types=1);

namespace OpenSim\Models;

use OpenSim\Provisioning\RegionName;
use OpenSim\Provisioning\Slug;

/**
 * A region: a square of the map of a grid, described by a file of the simulator that runs it (name, UUID, place, size, port).
 *
 * The fields of a region are defined here, for whoever shows them: the setup of a grid, its administration, the page of the owner
 * of a region (see Forms\FieldSet).
 */
final class Region
{
    /**
     * The fields of a region, in the standard format.
     *
     * @param array{optional?:bool,roles?:array<string,string>,pool?:array<string,string>,fixed?:bool} $options
     *        optional: the first region of a simulator, which can be left out (asked first); roles: the roles no region of the grid
     *        has yet (role => name); pool: the simulators that can take the region (instance => name), with a new one by default;
     *        fixed: a region that exists, which only moves (its name, its roles and its simulator stay)
     * @return list<array<string,mixed>>
     */
    public static function fields(array $options = []): array
    {
        $optional = ($options['optional'] ?? false) === true;
        $roles = $options['roles'] ?? [];
        $pool = $options['pool'] ?? [];
        $fixed = ($options['fixed'] ?? false) === true;
        // The fields of a region that is made are only there when it is
        $made = $optional ? ['create' => true] : [];

        return [
            [
                'name' => 'create',
                'group' => 'region',
                'type' => 'boolean',
                'label' => _('Create the first region of this simulator?'),
                'enable' => $optional,
            ],
            [
                'name' => 'name',
                'group' => 'region',
                'type' => 'string',
                'required' => true,
                'label' => _('Region name'),
                'callback' => [self::class, 'validateName'],
                'condition' => $made,
                'enable' => !$fixed,
            ],
            [
                'name' => 'roles',
                'group' => 'region',
                'type' => 'checkbox',
                'multiple' => true,
                'label' => _('Role of this region'),
                'options' => $roles,
                'condition' => $made,
                'enable' => $roles !== [] && !$fixed,
            ],
            [
                'name' => 'location',
                'group' => 'region',
                'type' => 'string',
                'required' => true,
                'label' => _('Region location (x,y)'),
                'callback' => [Grid::class, 'validateCenter'],
                'condition' => $made,
            ],
            [
                'name' => 'sim',
                'group' => 'region',
                'type' => 'select',
                'label' => _('Simulator'),
                'options' => ['+' => _('Auto (add new)')] + $pool,
                'condition' => $made,
                'enable' => $pool !== [] && !$fixed,
            ],
            [
                'name' => 'port',
                'group' => 'region',
                'type' => 'integer',
                'required' => true,
                'follows' => ['sim'],
                'label' => _('Region port'),
                'callback' => [Grid::class, 'validatePort'],
                // The port of a region of a new simulator is in its block, known when the simulator is
                'condition' => $made + ($pool !== [] ? ['sim' => array_keys($pool)] : []),
            ],
        ];
    }

    /** The name of a region. An accent is not refused, it is transliterated (Noël becomes Noel). */
    public static function validateName(mixed $value): ?string
    {
        return RegionName::problem(Slug::ascii((string) $value));
    }
}
