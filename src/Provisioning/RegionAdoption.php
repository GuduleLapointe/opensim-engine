<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use InvalidArgumentException;
use OpenSim\Config\ConfigReader;
use OpenSim\Config\IniEditor;
use OpenSim\Models\Grid;
use OpenSim\Text\Uuid;

/** An existing region's INI configuration, retained without creating a replacement region or owner. */
final class RegionAdoption
{
    /** @return array{name:string,uuid:string,location:string,port:int,size:int,external_host:string} */
    public static function describe(string $configuration): array
    {
        $sections = ConfigReader::parse($configuration);
        if (count($sections) !== 1 || trim((string) array_key_first($sections)) === '') {
            throw new InvalidArgumentException('Adoption requires the configuration of exactly one region');
        }
        $name = (string) array_key_first($sections);
        $values = array_change_key_case($sections[$name], CASE_LOWER);
        $uuid = $values['regionuuid'] ?? '';
        $location = $values['location'] ?? '';
        $port = $values['internalport'] ?? '';
        if (!Uuid::isValid($uuid)) {
            throw new InvalidArgumentException('The existing region UUID is missing or invalid');
        }
        if (Grid::validateCenter($location) !== null) {
            throw new InvalidArgumentException('The existing region location is missing or invalid');
        }
        if (Grid::validatePort($port) !== null) {
            throw new InvalidArgumentException('The existing region port is missing or invalid');
        }
        return [
            'name' => $name, 'uuid' => $uuid, 'location' => $location, 'port' => (int) $port,
            'size' => (int) ($values['sizex'] ?? 256),
            'external_host' => $values['externalhostname'] ?? 'SYSTEMIP',
        ];
    }

    /** Registry identity by name, and name by UUID when the name is absent: false if absent, null if the registry could not be checked. */
    public static function apply(SimulatorPlan $plan, string $configuration, string|false|null $registeredUuid, string|false|null $registeredName = false): void
    {
        $region = self::describe($configuration);
        if ($registeredUuid === null) {
            throw new InvalidArgumentException('The grid registry is unavailable; the existing region identity cannot be verified');
        }
        if ($registeredName === null) {
            throw new InvalidArgumentException('The grid registry is unavailable; the existing region UUID cannot be verified');
        }
        if ($registeredName !== false && strcasecmp($registeredName, $region['name']) !== 0) {
            throw new InvalidArgumentException('The existing region UUID is registered under another name');
        }
        if ($registeredUuid !== false && strcasecmp($registeredUuid, $region['uuid']) !== 0) {
            throw new InvalidArgumentException('The grid has another UUID registered for this region name');
        }
        $plan->regionName = $region['name'];
        $plan->regionUuid = $region['uuid'];
        $plan->regionLocation = $region['location'];
        $plan->regionPort = $region['port'];
        $plan->regionSize = $region['size'];
        $plan->externalHost = $region['external_host'];
        $plan->existingRegionConfig = $configuration;
        $plan->createRegion = false;
        $plan->createOwner = false;
        $plan->enable = false;
        $plan->start = false;
    }

    /** The original INI, with only explicitly changed network/location settings updated. */
    public static function render(SimulatorPlan $plan): string
    {
        $source = self::describe($plan->existingRegionConfig);
        if ($source['uuid'] !== $plan->regionUuid || $source['name'] !== $plan->regionName) {
            throw new InvalidArgumentException('An adopted region keeps its original name and UUID');
        }
        $ini = new IniEditor($plan->existingRegionConfig);
        $changed = false;
        foreach ([
            'Location' => [$source['location'], $plan->regionLocation],
            'InternalPort' => [(string) $source['port'], (string) $plan->regionPort],
            'ExternalHostName' => [$source['external_host'], $plan->externalHost],
        ] as $key => [$original, $value]) {
            if ($original !== $value) {
                $ini->set($plan->regionName, $key, $value);
                $changed = true;
            }
        }
        return $changed ? $ini->toString() : $plan->existingRegionConfig;
    }
}
