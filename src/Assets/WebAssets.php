<?php

declare(strict_types=1);

namespace OpenSim\Assets;

use OpenSim\Config\GridConfig;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Models\Asset;
use OpenSim\Models\Grid;
use OpenSim\Models\Image;
use OpenSim\Network\GridInfoReader;
use OpenSim\Text\Uuid;

/**
 * The assets of a grid for the web: asked by their id, as a picture in the format of a page when they are images, whatever the type (texture, TGA, JPEG: the grid keeps
 * them as JPEG 2000), as they are for the other types. What the grid gave and what was converted are kept for a day; an id that
 * is nothing, or an asset that cannot be had or converted, is the picture of nothing.
 *
 *     $web = WebAssets::for($grid);
 *     ['data' => $bytes, 'mime' => $type] = $web->get('cb2052ae-d161-43e9-b11b-c834217823cd', 'png');
 */
final class WebAssets
{
    /** A transparent pixel: what is shown for what has no picture, when nothing better is given. */
    private const NOTHING = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * @param ?\Closure(string):?string $http the body of an address, null when it does not answer (default: curl)
     * @param ?string $placeholder the file of the picture of nothing (default: a transparent pixel)
     */
    public function __construct(
        private readonly Grid $grid,
        private readonly AssetCache $converted,
        private readonly AssetCache $sources,
        private readonly ConverterInterface $converter,
        private readonly ?\Closure $http = null,
        private readonly ?string $placeholder = null,
    ) {}

    /** The assets of a grid of this machine, kept in its cache folder. */
    public static function for(Grid $grid, ?ConverterInterface $converter = null): self
    {
        $root = $grid->environment()->layout->cacheRoot() ?: sys_get_temp_dir() . '/opensim-cache';
        $folder = $root . '/' . $grid->nick() . '/assets';

        return new self(
            $grid,
            new AssetCache("$folder/images", 86400, $grid->environment()->clock),
            new AssetCache("$folder/sources", 86400, $grid->environment()->clock),
            $converter ?? new ImagickConverter(),
            $grid->environment()->http,
        );
    }

    /**
     * The asset as a page shows it.
     *
     * @param string $format png, jpg...: for an image of any type, the other types are given as they are
     * @param ?int $width the width in pixels an image is resized to, the height following; null keeps its size
     * @return array{data:string,mime:string,found:bool}
     */
    public function get(string $id, string $format = Image::DEFAULT_FORMAT, ?int $width = null): array
    {
        $format = strtolower(ltrim($format, '.'));
        isset(Image::FORMATS[$format]) || ($format = Image::DEFAULT_FORMAT);
        $id = strtolower($id);
        $width = $width !== null && $width > 0 ? $width : null;
        $name = $width !== null ? "$id-$width.$format" : "$id.$format";
        if (preg_match('/^[0-9a-f]{8}(-[0-9a-f]{4}){3}-[0-9a-f]{12}$/', $id) !== 1 || Uuid::isNullKey($id)) {
            return $this->nothing();
        }
        if (($done = $this->converted->get($name)) !== null) {
            return [
                'data' => $done['data'],
                'mime' => (string) ($done['meta']['mime'] ?? Image::FORMATS[$format]),
                'found' => true,
            ];
        }

        $asset = $this->asset($id);
        if ($asset === null || ($data = $asset->data()) === null) {
            return $this->nothing();
        }
        if (!($asset instanceof Image)) {
            return ['data' => $data, 'mime' => $asset->mime(), 'found' => true];
        }
        $picture = $asset->render($this->converter, $format, $width);
        if ($picture === null) {
            return $this->nothing();
        }
        $this->converted->put($name, $picture, ['mime' => Image::FORMATS[$format]]);

        return ['data' => $picture, 'mime' => Image::FORMATS[$format], 'found' => true];
    }

    /** The asset, from what the grid gave a while ago, else from its asset service, else from its database. */
    private function asset(string $id): ?Asset
    {
        $config =
            $this->grid->directory() !== '' && is_dir($this->grid->directory())
                ? GridConfig::fromFolder($this->grid->directory())
                : new GridConfig();
        if (($kept = $this->sources->get($id)) !== null) {
            return $this->rebuilt($config, $id, $kept);
        }
        $http = $this->http ?? GridInfoReader::get(...);
        $asset = null;
        foreach (['asset', 'hg-asset'] as $key) {
            $address = $this->grid->address($key);
            $asset = $address !== null ? Asset::fromService($config, $address, $id, $http) : null;
            if ($asset !== null) {
                break;
            }
        }
        if ($asset === null) {
            try {
                $asset = Asset::find($config, $id);
            } catch (ChannelUnavailable) {
                $asset = null;
            }
        }
        if ($asset !== null && ($data = $asset->data()) !== null) {
            $this->sources->put($id, $data, ['type' => $asset->typeNumber(), 'name' => $asset->name()]);
        }

        return $asset;
    }

    /**
     * @param array{data:string,meta:array<string,mixed>} $kept
     */
    private function rebuilt(GridConfig $config, string $id, array $kept): Asset
    {
        $row = [
            'id' => $id,
            'name' => (string) ($kept['meta']['name'] ?? ''),
            'assetType' => (int) ($kept['meta']['type'] ?? 0),
            'data' => $kept['data'],
        ];
        $class = Asset::TYPES[$row['assetType']][2] ?? Asset::class;

        return new $class($config, $row);
    }

    /** @return array{data:string,mime:string,found:bool} */
    private function nothing(): array
    {
        $file = $this->placeholder;
        if ($file !== null && is_file($file)) {
            $mime =
                ['png' => 'image/png', 'jpg' => 'image/jpeg', 'gif' => 'image/gif'][
                    strtolower(pathinfo($file, PATHINFO_EXTENSION))
                ] ?? 'image/png';

            return ['data' => (string) file_get_contents($file), 'mime' => $mime, 'found' => false];
        }

        return ['data' => (string) base64_decode(self::NOTHING), 'mime' => 'image/png', 'found' => false];
    }
}
