<?php

declare(strict_types=1);

use OpenSim\Provisioning\RegionAdoption;
use OpenSim\Provisioning\SimulatorConfig;
use OpenSim\Provisioning\SimulatorPlan;
use OpenSim\Provisioning\SimulatorProvisioner;
use OpenSim\Provisioning\NullUi;
use OpenSim\Testing\Machine;

function existing_region_ini(): string
{
    return file_get_contents(dirname(__DIR__) . '/fixtures/existing-region.ini');
}

test('adopts the original region configuration', function () {
    $plan = new SimulatorPlan();
    $plan->createOwner = true;
    RegionAdoption::apply($plan, existing_region_ini(), 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
    expect($plan->regionName)->toBe('Existing Garden')
        ->and($plan->regionUuid)->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')
        ->and($plan->regionLocation)->toBe('120,130')
        ->and($plan->regionPort)->toBe(9101)
        ->and($plan->createRegion)->toBeFalse()
        ->and($plan->createOwner)->toBeFalse()
        ->and($plan->enable)->toBeFalse()
        ->and($plan->start)->toBeFalse()
        ->and((new SimulatorConfig())->renderRegion($plan))->toBe(existing_region_ini());
});

test('writes an adopted region without a placeholder', function () {
    $machine = new Machine();
    $plan = new SimulatorPlan();
    $plan->gridNick = 'demo';
    $plan->gridDir = $machine->grid;
    $plan->gridName = 'Demo';
    $plan->simulatorName = 'Imported';
    $plan->slug = 'demo.imported';
    $plan->coreDirectory = $machine->core;
    $plan->dataDirectory = $machine->root . '/data/imported';
    $plan->cacheDirectory = $machine->root . '/cache/imported';
    $plan->logsDirectory = $machine->root . '/logs';
    $plan->httpPort = 9100;
    RegionAdoption::apply($plan, existing_region_ini(), false);
    (new SimulatorProvisioner(new NullUi()))->apply($plan, $machine->environment);
    expect(file_get_contents($plan->regionIni()))->toBe(existing_region_ini())
        ->and(glob($plan->regionsDir() . '/*.ini'))->toBe([$plan->regionIni()])
        ->and($machine->processes->all())->toBe([])
        ->and(json_decode(file_get_contents($machine->grid . '/setup.json'), true)['simulators'][0]['regions'][0]['uuid'])
        ->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
});

test('updates only adopted region networking and location', function () {
    $plan = new SimulatorPlan();
    RegionAdoption::apply($plan, existing_region_ini(), false);
    $plan->regionPort = 9121;
    $plan->regionLocation = '140,150';
    $plan->externalHost = 'region.example.test';
    $text = (new SimulatorConfig())->renderRegion($plan);
    $values = OpenSim\Config\ConfigReader::parse($text)['Existing Garden'];
    expect($values)->toMatchArray([
        'RegionUUID' => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
        'Location' => '140,150', 'InternalPort' => '9121',
        'ExternalHostName' => 'region.example.test', 'ResolveAddress' => 'True',
        'SizeX' => '512', 'SizeY' => '256', 'EstateID' => '42', 'CustomSetting' => 'preserve-me',
    ]);
});

test('preserves a legacy region name', function () {
    $source = str_replace('Existing Garden', "Jardin d'été", existing_region_ini());
    $plan = new SimulatorPlan();
    RegionAdoption::apply($plan, $source, false);
    expect($plan->regionName)->toBe("Jardin d'été")
        ->and((new SimulatorConfig())->renderRegion($plan))->toBe($source);
});

test('rejects another region with the same name', function () {
    $plan = new SimulatorPlan();
    expect(fn() => RegionAdoption::apply($plan, existing_region_ini(), 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff'))
        ->toThrow(InvalidArgumentException::class, 'another UUID');
    expect($plan->regionUuid)->toBe('');
});

test('rejects a UUID registered under another name', function () {
    expect(fn() => RegionAdoption::apply(new SimulatorPlan(), existing_region_ini(), false, 'Another Garden'))
        ->toThrow(InvalidArgumentException::class, 'another name');
});

test('requires a reachable registry for adoption', function () {
    expect(fn() => RegionAdoption::apply(new SimulatorPlan(), existing_region_ini(), null))
        ->toThrow(InvalidArgumentException::class, 'registry');
});

test('keeps an unregistered existing UUID', function () {
    $plan = new SimulatorPlan();
    RegionAdoption::apply($plan, existing_region_ini(), false);
    expect($plan->regionUuid)->toBe('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
});

test('refuses an invalid region source', function (string $source) {
    expect(fn() => RegionAdoption::describe($source))->toThrow(InvalidArgumentException::class);
})->with([
    'no UUID' => ["[Existing Garden]\nLocation=120,130\nInternalPort=9101\n"],
    'null UUID' => [str_replace('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', '00000000-0000-0000-0000-000000000000', existing_region_ini())],
    'invalid port' => [str_replace('InternalPort = 9101', 'InternalPort = 0', existing_region_ini())],
    'invalid location' => [str_replace('Location = 120,130', 'Location = nowhere', existing_region_ini())],
    'multiple regions' => [existing_region_ini() . "[Another Garden]\nRegionUUID=bbbbbbbb-cccc-4ddd-8eee-ffffffffffff\n"],
]);
