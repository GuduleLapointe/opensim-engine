<?php

declare(strict_types=1);

/**
 * The assets of a grid: the record whatever it holds, the textures converted for the web, what the grid gave and what was converted
 * kept for a while. The databases are in memory (SQLite); the conversion is a fake, ImageMagick is not needed.
 */

use OpenSim\Assets\AssetCache;
use OpenSim\Assets\ConverterInterface;
use OpenSim\Assets\WebAssets;
use OpenSim\Config\GridConfig;
use OpenSim\Data\Connections;
use OpenSim\Data\OSPDO;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Asset;
use OpenSim\Models\Grid;
use OpenSim\Models\Image;
use OpenSim\Testing\ClockFake;
use OpenSim\Testing\Machine;

const ASSET_TEXTURE = 'cb2052ae-d161-43e9-b11b-c834217823cd';
const ASSET_NOTECARD = 'dddddddd-0000-4000-8000-000000000007';
const ASSET_TGA = 'aaaaaaaa-0000-4000-8000-000000000018';
const ASSET_JPEG = 'bbbbbbbb-0000-4000-8000-000000000019';

/** A grid folder whose Robust says where the assets are. */
function asset_config(): GridConfig
{
    $dir = test_tmp() . '/assets-' . bin2hex(random_bytes(4));
    mkdir($dir, 0o755, true);
    file_put_contents(
        "$dir/Robust.HG.ini",
        "[DatabaseService]\n ConnectionString = \"Data Source=localhost;Database=robust;User ID=os;Password=pw;\"\n",
    );

    return GridConfig::fromFolder($dir);
}

/** A table of assets with a texture, a notecard, a script and two other pictures. */
function asset_database(): OSPDO
{
    $pdo = new OSPDO(['driver' => 'sqlite', 'name' => ':memory:']);
    $pdo->exec(
        'CREATE TABLE assets (name VARCHAR(64), description VARCHAR(64), assetType INT, local INT, temporary INT, data BLOB, id CHAR(36) PRIMARY KEY, create_time INT, access_time INT, asset_flags INT, CreatorID VARCHAR(128))',
    );
    $pdo->exec(
        "INSERT INTO assets VALUES
        ('Garden picture', 'A rose', 0, 0, 0, 'JP2-BYTES', '" .
            ASSET_TEXTURE .
            "', 1700000000, 1700001000, 0, '11111111-1111-1111-1111-111111111111'),
        ('Welcome card', 'Read me', 7, 0, 0, 'Linden text version 2', '" .
            ASSET_NOTECARD .
            "', 1600000000, 0, 0, '00000000-0000-0000-0000-000000000000'),
        ('Door script', '', 10, 0, 1, 'default { }', 'eeeeeeee-0000-4000-8000-000000000010', 1650000000, 0, 0, '11111111-1111-1111-1111-111111111111'),
        ('Old picture', 'A scan', 18, 0, 0, 'TGA-BYTES', '" .
            ASSET_TGA .
            "', 1500000000, 0, 0, '11111111-1111-1111-1111-111111111111'),
        ('Photo', 'From a phone', 19, 0, 0, 'JPEG-BYTES', '" .
            ASSET_JPEG .
            "', 1550000000, 0, 0, '11111111-1111-1111-1111-111111111111')",
    );

    return $pdo;
}

/** A converter that makes a text of what it is given. */
function asset_converter(array &$calls): ConverterInterface
{
    return new class ($calls) implements ConverterInterface {
        public function __construct(private array &$calls) {}

        public function convert(string $data, string $format): ?string
        {
            $this->calls[] = $format;

            return in_array($data, ['JP2-BYTES', 'TGA-BYTES', 'JPEG-BYTES'], true) ? "$format:$data" : null;
        }
    };
}

afterEach(fn() => Connections::using(null));

describe('assets', function () {
    test('are listed without their data, searched, filtered by type and sorted', function () {
        Connections::using(static fn() => asset_database());
        $config = asset_config();

        $all = Asset::all($config);
        $notes = Asset::all($config, ['filters' => ['type' => 'notecard']]);

        expect($all->total())
            ->toBe(5)
            ->and(array_column($all->array(), 'name'))
            ->toBe(['Door script', 'Garden picture', 'Old picture', 'Photo', 'Welcome card'])
            ->and($all->first()->detail())
            ->toMatchArray(['type' => 'script', 'typeNumber' => 10, 'temporary' => true, 'size' => 11])
            ->and($notes->total())
            ->toBe(1)
            ->and($notes->applied())
            ->toBe(['type' => 'notecard'])
            ->and(Asset::all($config, ['search' => 'rose'])->total())
            ->toBe(1)
            ->and(array_column(Asset::all($config, ['sort' => 'created desc'])->array(), 'name'))
            ->toBe(['Garden picture', 'Door script', 'Welcome card', 'Photo', 'Old picture'])
            ->and(array_column(Asset::all($config, ['filters' => ['type' => 'image-tga']])->array(), 'name'))
            ->toBe(['Old picture'])
            ->and(Asset::all($config, ['filters' => ['temporary' => 'yes']])->total())
            ->toBe(1);
    });

    test('are each of the class of their type, the same record for all of them', function () {
        Connections::using(static fn() => asset_database());
        $config = asset_config();

        $texture = Asset::find($config, ASSET_TEXTURE);
        $note = Asset::find($config, ASSET_NOTECARD);

        expect($texture)
            ->toBeInstanceOf(Image::class)
            ->and($texture->type())
            ->toBe('texture')
            ->and($texture->mime())
            ->toBe('image/x-j2c')
            ->and($texture->owner())
            ->toBe('11111111-1111-1111-1111-111111111111')
            ->and($texture->created()->getTimestamp())
            ->toBe(1700000000)
            ->and($note)
            ->not->toBeInstanceOf(Image::class)
            ->and($note->type())
            ->toBe('notecard')
            ->and($note->owner())
            ->toBeNull()
            ->and($note->data())
            ->toBe('Linden text version 2')
            ->and($note->channels())
            ->toBe(['database'])
            ->and(Asset::find($config, 'not an id'))
            ->toBeNull()
            ->and(Asset::find($config, 'ffffffff-0000-4000-8000-000000000000'))
            ->toBeNull();
    });

    test('are an Image for every type of picture, texture, TGA or JPEG', function () {
        Connections::using(static fn() => asset_database());
        $config = asset_config();

        foreach ([ASSET_TEXTURE => 'texture', ASSET_TGA => 'image-tga', ASSET_JPEG => 'image-jpeg'] as $id => $type) {
            expect(Asset::find($config, $id))
                ->toBeInstanceOf(Image::class)
                ->and(Asset::find($config, $id)->type())
                ->toBe($type);
        }
        expect(Asset::find($config, ASSET_JPEG)->mime())
            ->toBe('image/jpeg')
            ->and(Asset::find($config, ASSET_TGA)->mime())
            ->toBe('image/tga');
    });

    test('are not made, changed or deleted by the engine: the grid does it', function () {
        Connections::using(static fn() => asset_database());
        $config = asset_config();

        expect(fn() => Asset::create($config, []))
            ->toThrow(ChannelUnavailable::class)
            ->and(fn() => Asset::find($config, ASSET_NOTECARD)->delete())
            ->toThrow(ChannelUnavailable::class);
    });

    test('are read from the asset service of the grid, whatever it stores them in', function () {
        $xml =
            '<?xml version="1.0"?><AssetBase><Data>' .
            base64_encode('hello') .
            '</Data><FullID><Guid>' .
            ASSET_NOTECARD .
            '</Guid></FullID><ID>' .
            ASSET_NOTECARD .
            '</ID><Name>From files</Name><Description/><Type>7</Type><Local>false</Local><Temporary>true</Temporary><CreatorID>x</CreatorID></AssetBase>';
        $asked = [];
        $http = static function (string $url) use ($xml, &$asked): ?string {
            $asked[] = $url;

            return str_ends_with($url, ASSET_NOTECARD) ? $xml : null;
        };

        $asset = Asset::fromService(asset_config(), 'http://play.example.org:8003/', ASSET_NOTECARD, $http);

        expect($asset->name())
            ->toBe('From files')
            ->and($asset->type())
            ->toBe('notecard')
            ->and($asset->isTemporary())
            ->toBeTrue()
            ->and($asset->data())
            ->toBe('hello')
            ->and($asset->size())
            ->toBe(5)
            ->and($asked)
            ->toBe(['http://play.example.org:8003/assets/' . ASSET_NOTECARD])
            ->and(Asset::fromService(asset_config(), 'http://play.example.org:8003', ASSET_TEXTURE, $http))
            ->toBeNull()
            ->and(Asset::fromService(asset_config(), 'http://play.example.org:8003', 'nope', $http))
            ->toBeNull();
    });
})->skip(fn() => !extension_loaded('pdo_sqlite'), 'pdo_sqlite is missing');

describe('assets for the web', function () {
    /** The grid `demo` whose asset service answers the assets it is given, and the folders of the cache. */
    function web_assets(array $assets, array &$calls, ?string $placeholder = null): array
    {
        $machine = new Machine();
        $http = static fn(string $url): ?string => $assets[basename($url)] ?? null;
        $grid = Grid::find($machine->environment, 'demo');
        $folder = test_tmp() . '/web-assets-' . bin2hex(random_bytes(4));
        $clock = new ClockFake();
        $web = new WebAssets(
            $grid,
            new AssetCache("$folder/images", 100, $clock),
            new AssetCache("$folder/sources", 100, $clock),
            asset_converter($calls),
            $http,
            $placeholder,
        );

        return [$web, $clock, $folder];
    }

    function asset_xml(string $id, int $type, string $data): string
    {
        return '<AssetBase><Data>' .
            base64_encode($data) .
            "</Data><ID>$id</ID><Name>x</Name><Type>$type</Type></AssetBase>";
    }

    test('give a texture as a picture of the web, converted once and kept', function () {
        $calls = [];
        [$web, $clock] = web_assets([ASSET_TEXTURE => asset_xml(ASSET_TEXTURE, 0, 'JP2-BYTES')], $calls);

        $first = $web->get(ASSET_TEXTURE, 'png');
        $second = $web->get(strtoupper(ASSET_TEXTURE), '.png');
        $jpg = $web->get(ASSET_TEXTURE);

        expect($first)
            ->toBe(['data' => 'png:JP2-BYTES', 'mime' => 'image/png', 'found' => true])
            ->and($second)
            ->toBe($first)
            ->and($jpg)
            ->toBe(['data' => 'jpg:JP2-BYTES', 'mime' => 'image/jpeg', 'found' => true])
            ->and($calls)
            ->toBe(['png', 'jpg']);
        $clock->now += 101;
        $web->get(ASSET_TEXTURE, 'png');
        expect($calls)->toBe(['png', 'jpg', 'png']);
    });

    test(
        'give the other types as they are, and the picture of nothing for what is not there or cannot be converted',
        function () {
            $calls = [];
            [$web] = web_assets(
                [
                    ASSET_NOTECARD => asset_xml(ASSET_NOTECARD, 7, 'a note'),
                    ASSET_TEXTURE => asset_xml(ASSET_TEXTURE, 0, 'broken'),
                ],
                $calls,
            );

            $note = $web->get(ASSET_NOTECARD, 'png');
            $nothing = $web->get('ffffffff-0000-4000-8000-000000000000');

            expect($note)
                ->toBe(['data' => 'a note', 'mime' => 'application/vnd.ll.notecard', 'found' => true])
                ->and($nothing['found'])
                ->toBeFalse()
                ->and($nothing['mime'])
                ->toBe('image/png')
                ->and(substr($nothing['data'], 1, 3))
                ->toBe('PNG')
                ->and($web->get('00000000-0000-0000-0000-000000000000')['found'])
                ->toBeFalse()
                ->and($web->get('not an id')['found'])
                ->toBeFalse()
                ->and($web->get(ASSET_TEXTURE, 'png')['found'])
                ->toBeFalse();
        },
    );

    test('use the picture of nothing the grid gives, when it gives one', function () {
        $file = test_tmp() . '/nothing-' . bin2hex(random_bytes(4)) . '.jpg';
        file_put_contents($file, 'JPEG-OF-NOTHING');
        $calls = [];
        [$web] = web_assets([], $calls, $file);

        expect($web->get(ASSET_TEXTURE))->toBe(['data' => 'JPEG-OF-NOTHING', 'mime' => 'image/jpeg', 'found' => false]);
    });
});

describe('the cache of assets', function () {
    test('keeps the data with what is known of it, and forgets it when it is empty or old', function () {
        $clock = new ClockFake();
        $cache = new AssetCache(test_tmp() . '/asset-cache-' . bin2hex(random_bytes(4)), 60, $clock);

        expect($cache->put('a/b c', 'data', ['type' => 7]))
            ->toBeTrue()
            ->and($cache->get('a/b c'))
            ->toBe(['data' => 'data', 'meta' => ['type' => 7]])
            ->and($cache->put('empty', ''))
            ->toBeFalse()
            ->and($cache->get('empty'))
            ->toBeNull();
        $clock->now += 61;
        expect($cache->get('a/b c'))->toBeNull();
    });
});

describe('the Imagick converter', function () {
    test('makes the formats of the web from an image', function () {
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
        );
        $converter = new OpenSim\Assets\ImagickConverter();

        expect(substr((string) $converter->convert($png, 'jpg'), 0, 3))
            ->toBe("\xFF\xD8\xFF")
            ->and($converter->convert('not an image', 'jpg'))
            ->toBeNull()
            ->and($converter->convert('', 'png'))
            ->toBeNull();
    })->skip(fn() => !extension_loaded('imagick'), 'imagick is missing');
});
