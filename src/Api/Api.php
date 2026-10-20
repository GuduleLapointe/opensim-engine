<?php

declare(strict_types=1);

namespace OpenSim\Api;

use OpenSim\Result;
use OpenSim\Config\GridConfig;
use OpenSim\Exceptions\ChannelUnavailable;
use OpenSim\Exceptions\NotFound;
use OpenSim\Interfaces\ModelInterface;
use OpenSim\Assets\ConverterInterface;
use OpenSim\Assets\ImagickTextRenderer;
use OpenSim\Assets\TextRendererInterface;
use OpenSim\Assets\WebAssets;
use OpenSim\Models\Archive;
use OpenSim\Models\Asset;
use OpenSim\Models\Avatar;
use OpenSim\Models\Estate;
use OpenSim\Models\Grid;
use OpenSim\Provisioning\QuickSetup;
use OpenSim\Models\Parcel;
use OpenSim\Models\Region;
use OpenSim\Models\Service;
use OpenSim\Models\Simulator;
use OpenSim\Runtime\Environment;
use OpenSim\Data\Connections;
use OpenSim\Data\Database;
use OpenSim\Provisioning\Slug;
use OpenSim\Economy\Currency;
use OpenSim\Health\Health;
use OpenSim\Runtime\GridMonitor;
use OpenSim\Economy\CurrencySettings;
use OpenSim\Services\CurrencyServer;
use OpenSim\Services\Failure;
use OpenSim\Text\DestinationList;
use OpenSim\Services\MailerInterface;
use OpenSim\Services\OfflineMessages;
use OpenSim\Services\PhpMailer;
use OpenSim\Services\SearchParser;
use OpenSim\Services\SearchQuery;
use OpenSim\Services\SearchRegistry;
use OpenSim\Services\SearchTables;
use OpenSim\Services\XmlRpc;
use OpenSim\Scheduling\Scheduler;

/**
 * What the machine offers as resources, listed, read and changed the way JSON:API says, the errors told as problems (RFC 9457). The
 * use cases are the models': this layer only names the resources, reads the query and writes the documents, so that the command
 * line, the HTTP API and the PHP library show the same.
 *
 *     $api = new Api($environment, $scheduler);
 *     $api->list('services', ['filter' => ['served' => 'yes'], 'sort' => '-name', 'page' => ['number' => 2, 'size' => 20]]);
 *     $api->get('grids', 'vonda');
 *     $api->list('avatars', ['search' => 'ada'], grid: 'vonda');                      // the resources of a grid are asked with it
 *     $api->create('avatars', ['name' => 'Ada Love', 'password' => 'secret'], grid: 'vonda');
 *     $api->update('simulators', 'vonda_welcome', ['enabled' => true]);
 *     $api->run('search-parse');
 *
 * The contract of these resources is Contract::document(), which the tests check against the models.
 */
final class Api
{
    /** The resources of the machine, by the name they have in an address. */
    public const RESOURCES = [
        'grids' => Grid::class,
        'simulators' => Simulator::class,
        'services' => Service::class,
        'archives' => Archive::class,
    ];

    /** The resources of a grid: read in its databases and its files, at `/grids/{grid}/{type}`. */
    public const GRID_RESOURCES = [
        'avatars' => Avatar::class,
        'regions' => Region::class,
        'parcels' => Parcel::class,
        'estates' => Estate::class,
        'assets' => Asset::class,
    ];

    /** Seconds a simulator that registers is given to load its regions before its snapshot is read. */
    public const REGISTER_DELAY = 30;

    /** The forms the engine knows (what a setup asks), by name. */
    public const FORMS = ['grid', 'remote-grid', 'simulator', 'region'];

    /** @var \Closure(Grid):?GridConfig the config of a grid, from its folder unless the caller says otherwise */
    private \Closure $config;

    /** @var list<\Closure():void> what is done once the answer is sent (see finish()) */
    private array $after = [];

    /** @var array<string,true> the search databases whose tables are checked */
    private static array $prepared = [];

    /**
     * @param ?Scheduler $scheduler the tasks of the application, none when it has none
     * @param ?\Closure(Grid):?GridConfig $config the config of a grid (default: its folder)
     * @param ?TextRendererInterface $texts draws the images of a text (default: ImageMagick)
     * @param ?MailerInterface $mailer sends the mails of the grid (default: the mail function of PHP)
     */
    public function __construct(
        private readonly Environment $environment,
        private readonly ?Scheduler $scheduler = null,
        ?\Closure $config = null,
        private readonly ?TextRendererInterface $texts = null,
        private readonly ?MailerInterface $mailer = null,
    ) {
        $this->config =
            $config ??
            static fn(Grid $grid): ?GridConfig => $grid->directory() !== '' && is_dir($grid->directory())
                ? GridConfig::fromFolder($grid->directory(), ['Helpers' => 'Helpers.ini'])
                : null;
    }

    /**
     * A page of the resources of a type.
     *
     * @param array<string,mixed> $query `search`, `sort` ("-name" is name descending), `filter[<name>]`, `page[number]`, `page[size]`
     * @param ?string $grid the nick of the grid, for the resources of a grid
     */
    public function list(string $type, array $query = [], ?string $grid = null): Response
    {
        $instance = self::path($type, null, $grid);
        try {
            if ($type === 'tasks') {
                return $this->tasks($instance);
            }
            if ($type === 'forms') {
                return Response::document([
                    'data' => array_map(fn(string $name): array => $this->form($name), self::FORMS),
                    'links' => ['self' => $instance],
                ]);
            }
            if ($type === 'fonts') {
                $fonts = array_map(
                    static fn(string $name): array => self::fontResource($name),
                    $this->renderer()->fonts(),
                );

                return Response::document([
                    'data' => $fonts,
                    'meta' => ['total' => count($fonts)],
                    'links' => ['self' => $instance],
                ]);
            }
            if ($type === 'destinations' && $grid !== null && ($query['format'] ?? '') === 'list') {
                $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");

                return Response::binary(
                    DestinationList::format($found->destinations(($this->config)($found))),
                    'text/plain; charset=utf-8',
                );
            }
            if ($type === 'destinations' && $grid !== null) {
                $destinations = $this->destinations($grid);

                return Response::document([
                    'data' => array_values($destinations),
                    'meta' => [
                        'total' => count($destinations),
                        'categories' => array_values(
                            array_unique(array_column(array_column($destinations, 'attributes'), 'category')),
                        ),
                    ],
                    'links' => ['self' => $instance],
                ]);
            }
            $class = self::classOf($type, $grid);
            if ($class === null) {
                return Response::problem(
                    Problem::notFound(
                        "kind of resource called '$type'" . ($grid !== null ? ' in a grid' : ''),
                        $instance,
                    ),
                );
            }
            $options = self::options($query);
            $collection = isset(self::RESOURCES[$type])
                ? $class::all($this->environment, $options)
                : $class::all($this->gridConfig((string) $grid), $options);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => array_map(
                fn(ModelInterface $model): array => self::resource($type, $model, $grid),
                $collection->items(),
            ),
            'meta' => [
                'total' => $collection->total(),
                'page' => $collection->page(),
                'perPage' => $collection->perPage(),
                'sort' => $collection->sort(),
                'search' => $collection->search(),
                'filters' => $collection->applied(),
            ],
            'links' => ['self' => $instance],
        ]);
    }

    /** One resource by its id. */
    public function get(string $type, string $id, ?string $grid = null): Response
    {
        $instance = self::path($type, $id, $grid);
        try {
            if ($type === 'tasks') {
                return $this->task($id, $instance);
            }
            if ($type === 'fonts') {
                return in_array($id, $this->renderer()->fonts(), true)
                    ? Response::document(['data' => self::fontResource($id), 'links' => ['self' => $instance]])
                    : Response::problem(Problem::notFound("font called '$id'", $instance));
            }
            if ($type === 'destinations' && $grid !== null) {
                $found = $this->destinations($grid)[$id] ?? null;

                return $found !== null
                    ? Response::document(['data' => $found, 'links' => ['self' => $instance]])
                    : Response::problem(Problem::notFound("destination called '$id'", $instance));
            }
            if ($type === 'forms') {
                return in_array($id, self::FORMS, true)
                    ? Response::document(['data' => $this->form($id), 'links' => ['self' => $instance]])
                    : Response::problem(Problem::notFound("form called '$id'", $instance));
            }
            $model = $this->find($type, $id, $grid, $instance);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return $model instanceof Response
            ? $model
            : Response::document(['data' => self::resource($type, $model, $grid), 'links' => ['self' => $instance]]);
    }

    /**
     * Make a resource (an avatar; the others are made by the setup and answer that no channel does it).
     *
     * @param array<string,mixed> $attributes the values of the new resource, by the names of its attributes
     */
    public function create(string $type, array $attributes, ?string $grid = null): Response
    {
        $instance = self::path($type, null, $grid);
        try {
            $class = self::classOf($type, $grid);
            if ($class === null) {
                return Response::problem(Problem::notFound("kind of resource called '$type'", $instance));
            }
            $created = isset(self::RESOURCES[$type])
                ? $class::create($this->environment, $attributes)
                : $class::create($this->gridConfig((string) $grid), $attributes);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        $self = self::path($type, (string) $created->id(), $grid);

        return new Response(
            201,
            ['Content-Type' => Response::JSON_API, 'Location' => $self],
            ['data' => self::resource($type, $created, $grid), 'links' => ['self' => $self]],
        );
    }

    /**
     * Change a resource. `enabled` enables or disables it, the other attributes are given to its `update()`.
     *
     * @param array<string,mixed> $attributes
     */
    public function update(string $type, string $id, array $attributes, ?string $grid = null): Response
    {
        $instance = self::path($type, $id, $grid);
        try {
            $model = $this->find($type, $id, $grid, $instance);
            if ($model instanceof Response) {
                return $model;
            }
            if (array_key_exists('enabled', $attributes)) {
                Result::isTrue($attributes['enabled']) ? $model->enable() : $model->disable();
                unset($attributes['enabled']);
            }
            $attributes !== [] && $model->update($attributes);
            $model = $this->find($type, $id, $grid, $instance);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return $model instanceof Response
            ? $model
            : Response::document(['data' => self::resource($type, $model, $grid), 'links' => ['self' => $instance]]);
    }

    /** Delete a resource, when a channel can. */
    public function delete(string $type, string $id, ?string $grid = null): Response
    {
        $instance = self::path($type, $id, $grid);
        try {
            $model = $this->find($type, $id, $grid, $instance);
            if ($model instanceof Response) {
                return $model;
            }
            $model->delete();
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return new Response(204, [], []);
    }

    /**
     * An asset of a grid as a page shows it: an image of any type as a picture in the format asked, resized to a width when it is asked
     * (the grid keeps textures as JPEG 2000), the other types as they are. What is not there is the picture of nothing, with the status
     * 404.
     */
    public function media(
        string $grid,
        string $id,
        string $format = 'jpg',
        ?ConverterInterface $converter = null,
        ?int $width = null,
    ): Response {
        $instance = self::path('assets', $id, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
            $picture = WebAssets::for($found, $converter)->get($id, $format, $width);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::binary($picture['data'], $picture['mime'], $picture['found'] ? 200 : 404);
    }

    /**
     * The message of the day of a grid, as plain text: what its viewers show at login, `<USERNAME>` replaced when a name is given.
     */
    public function motd(string $grid, string $username = ''): Response
    {
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, self::path('motd', null, $grid)));
        }

        return Response::binary($found->motd($username), 'text/plain; charset=utf-8');
    }

    /**
     * The picture of a text, the texture of a prim: square, the text centered.
     *
     * @param array<string,mixed> $query `text` (the name of the font when there is none), `font`, `size` (1 to 1000), `color`,
     *        `background`, `download` (offer it as a file)
     */
    public function textImage(array $query = []): Response
    {
        try {
            $font = (string) ($query['font'] ?? 'Impact');
            $size = (int) ($query['size'] ?? 120);
            $text = mb_substr((string) ($query['text'] ?? $font), 0, 500);
            $png =
                $this->renderer()->render(
                    $text,
                    $font,
                    $size > 0 ? min($size, 1000) : 120,
                    (string) ($query['color'] ?? 'white'),
                    (string) ($query['background'] ?? 'black'),
                ) ?? throw ChannelUnavailable::for('draw the text', 'this server has no Imagick (php-imagick)');
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, '/text-images'));
        }
        $name = mb_substr(trim((string) preg_replace('/[^\w\- .]+/u', '', $text)), 0, 60);

        return Response::binary(
            $png,
            'image/png',
            headers: Result::isTrue($query['download'] ?? false)
                ? ['Content-Disposition' => 'attachment; filename="' . ($name === '' ? 'text' : $name) . '.png"']
                : [],
        );
    }

    /**
     * The registration of a simulator to the search: it tells that it is online (it starts, its regions are to be read) or offline
     * (it stops). What OpenSimSearch calls is fixed: a GET with the `host` and the `port` of the simulator (not those of the grid) and
     * the `service`. The answer is nothing; the simulator waits for it to go on starting, so the snapshot is read once it is sent
     * (see finish()).
     *
     * @param array<string,mixed> $query `host`, `port`, `service` (`online` or `offline`)
     */
    public function register(string $grid, array $query): Response
    {
        $host = trim((string) ($query['host'] ?? ''));
        $port = (int) ($query['port'] ?? 0);
        $service = (string) ($query['service'] ?? '');
        try {
            if ($host === '' || $port <= 0) {
                throw new Failure('Bad request: missing simulator host and/or port', 400);
            }
            if (!in_array($service, ['online', 'offline'], true)) {
                throw new Failure('Bad request: service is online or offline', 400);
            }
            [$db, $regions] = $this->searchDatabase($grid);
            $registry = new SearchRegistry($db, $regions, $this->environment->clock);
            if ($service === 'offline') {
                $registry->unregister($host, $port);
            } else {
                $registry->register($host, $port);
                // A simulator registers while it starts: its regions are loaded a little later, and it keeps the snapshot it makes
                // when they are. It is read then, and again soon after
                $this->after[] = function () use ($db, $regions, $registry, $host, $port): void {
                    $this->environment->clock->sleep(self::REGISTER_DELAY);
                    (new SearchParser($registry, $db, $regions, $this->environment->http))->parse();
                    $registry->again($host, $port);
                };
            }
        } catch (Failure $failure) {
            return Response::binary($failure->getMessage() . "\n", 'text/plain; charset=utf-8', $failure->status);
        } catch (\Throwable $error) {
            return Response::binary("Internal error\n", 'text/plain; charset=utf-8', 500);
        }

        return Response::binary('', 'text/plain; charset=utf-8');
    }

    /**
     * The questions of the viewers to the search of a grid (OpenSimSearch): XML-RPC, the methods and the answers fixed by the
     * viewers. The search can be shared by several grids: `gk` in the address, which the viewers do not send, says for which grid
     * the search is made.
     *
     * @param array<string,mixed> $query `gk`, the gatekeeper of the grid that asks
     */
    public function searchQuery(string $grid, string $body, array $query = []): Response
    {
        try {
            [$db, $regions, $config] = $this->searchDatabase($grid);
            $profiles = Connections::pdo($config, Database::Profiles);
            $methods = new SearchQuery(
                $db,
                $regions,
                $profiles?->connected ? $profiles : null,
                Connections::pdo($config, 'UserAccountService'),
                Connections::pdo($config, 'PresenceService'),
                isset($query['gk']) ? (string) $query['gk'] : null,
                $this->environment->clock,
            );
        } catch (Failure $failure) {
            return XmlRpc::failure($failure);
        }

        return (new XmlRpc($methods->methods()))->call($body);
    }

    /**
     * What the simulators ask of the offline messages (the module V1 of OpenSimulator): `SaveMessage` and `RetrieveMessages`, the
     * messages in the XML of the module. The mails to the avatars who want them are sent once the answer is sent (see finish()).
     */
    public function offline(string $grid, string $method, string $body): Response
    {
        try {
            $found = Grid::find($this->environment, $grid) ?? throw new Failure("There is no grid called '$grid'", 404);
            $config = ($this->config)($found) ?? throw new Failure("There is no config for the grid '$grid'", 404);
            $messages = Connections::pdo($config, Database::Offline);
            if ($messages === null || !$messages->connected) {
                throw new Failure('Bad configuration: the database of the offline messages is not available', 503);
            }
            $profiles = Connections::pdo($config, Database::Profiles);
            $service = new OfflineMessages(
                $messages,
                $profiles?->connected ? $profiles : null,
                $found->name(),
                $config->find('Helpers', 'MailSender') ?? 'no-reply@' . $found->baseHostname(),
                $this->mailer ?? new PhpMailer(),
            );
        } catch (Failure $failure) {
            return XmlRpc::failure($failure);
        }
        $answer = $service->call($method, $body);
        $this->after[] = $service->finish(...);

        return $answer;
    }

    /**
     * What the viewers and the simulators ask of the currency of a grid (`currency.php` of its economy): XML-RPC, the methods and
     * the answers fixed by the viewers and the money modules.
     *
     * @param string $ip the address of whoever calls: a quote of currency is tied to it
     */
    public function currency(string $grid, string $body, string $ip = ''): Response
    {
        try {
            return (new XmlRpc($this->economy($grid, $ip)->currencyMethods()))->call($body);
        } catch (Failure $failure) {
            return XmlRpc::failure($failure);
        }
    }

    /** What the viewers ask of the land tools of a grid (`landtool.php` of its economy): XML-RPC, the answers fixed by the viewers. */
    public function landTool(string $grid, string $body, string $ip = ''): Response
    {
        try {
            return (new XmlRpc($this->economy($grid, $ip)->landMethods()))->call($body);
        } catch (Failure $failure) {
            return XmlRpc::failure($failure);
        }
    }

    /**
     * The currency of a grid, served.
     *
     * @throws Failure when the grid is not known, or has no database to keep the money
     */
    private function economy(string $nick, string $ip): CurrencyServer
    {
        $grid = Grid::find($this->environment, $nick) ?? throw new Failure("There is no grid called '$nick'", 404);
        $config = ($this->config)($grid) ?? throw new Failure("There is no config for the grid '$nick'", 404);
        $money = Connections::pdo($config, Database::Economy) ?? Connections::pdo($config, Database::Robust);
        if ($money === null || !$money->connected) {
            throw new Failure('Bad configuration: the database of the currency is not available', 503);
        }
        $settings = CurrencySettings::fromConfig($config, (string) $grid->address('currency'));

        return new CurrencyServer(
            new Currency(
                $settings,
                static fn(string $service): ?\OpenSim\Data\OSPDO => Connections::pdo($config, $service),
                $money,
            ),
            $ip,
        );
    }

    /**
     * The setup the answers of the quick setup stand for, with the proposals for the rest, and what is wrong with the answers.
     *
     * @param array<string,mixed> $answers type, name, login_uri, avatar, password, email
     */
    public function quickSetup(array $answers): Response
    {
        try {
            $result = QuickSetup::plan($this->environment, $answers);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, '/setups/quick'));
        }

        return Response::document([
            'data' => ['type' => 'setups', 'id' => 'quick', 'attributes' => $result],
            'links' => ['self' => '/setups/quick'],
        ]);
    }

    /** One step of the quick setup: the screen, filled, in; the next screen or the action to do, out (see QuickSetup::next()). */
    public function setupStep(array $attributes): Response
    {
        try {
            $result = QuickSetup::next($this->environment, $attributes);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, '/setups/steps'));
        }

        return Response::document([
            'data' => ['type' => 'steps', 'id' => 'quick', 'attributes' => $result],
            'links' => ['self' => '/setups/steps'],
        ]);
    }

    /**
     * The addresses of a grid: the ones it gives its viewers and its simulators (login, economy, search, registration, offline
     * messages, message of the day, guide...), each with where it comes from, and the ones the helpers need to know first.
     */
    public function addresses(string $grid): Response
    {
        $instance = self::path('addresses', null, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
            $addresses = $found->addresses();
            $sources = [];
            foreach (array_keys($addresses) as $key) {
                $sources[$key] = $found->service($key)?->source();
            }
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'addresses',
                'id' => $found->nick(),
                'attributes' => [
                    'login' => $found->loginUrl(),
                    'uri' => $found->uri(),
                    'helpers' => $found->helpersUrl(),
                    'economy' => $found->economy(),
                    'search' => $found->searchUrl(),
                    'searchApi' => $found->searchApi(),
                    'register' => $found->registerUrl(),
                    'offline' => $found->offlineUrl(),
                    'addresses' => $addresses,
                    'sources' => $sources,
                ],
                'links' => ['self' => $instance],
            ],
        ]);
    }

    /**
     * What a grid says of itself (`get_grid_info`), every element it gave, the free ones included: as it said them when it was last
     * verified, else what its config says (`live` tells which).
     */
    public function info(string $grid): Response
    {
        $instance = self::path('info', null, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'info',
                'id' => $found->nick(),
                'attributes' => [
                    'live' => $found->gridInfoIsLive(),
                    'login' => $found->loginUrl(),
                    'uri' => $found->uri(),
                    'elements' => (object) $found->gridInfo(),
                ],
                'links' => ['self' => $instance],
            ],
        ]);
    }

    /**
     * The state of a grid at a glance: its Robust, its simulators, its regions, who is in world. `format=text` gives the lines of
     * `opensim status`, `format=png` a picture of them for a prim.
     *
     * @param array<string,mixed> $query `format` (json, text or png); for png, `size` of the font, `color`, `background`
     */
    public function monitor(string $grid, array $query = []): Response
    {
        $instance = self::path('monitor', null, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
            $monitor = GridMonitor::of($found, ($this->config)($found));
            $text = GridMonitor::text($monitor);
            $format = (string) ($query['format'] ?? 'json');
            if ($format === 'text') {
                return Response::binary($text, 'text/plain; charset=utf-8');
            }
            if ($format === 'png') {
                $longest = max(array_map('strlen', explode("\n", $text)));
                $png =
                    $this->renderer()->render(
                        $text,
                        'DejaVu-Sans-Mono',
                        (int) ($query['size'] ?? max(10, min(80, (int) (1000 / max(1, $longest * 0.62))))),
                        (string) ($query['color'] ??
                            (['ok' => '#88ff88', 'degraded' => '#ffcc44', 'down' => '#ff6666'][$monitor['level']] ??
                                'white')),
                        (string) ($query['background'] ?? 'black'),
                    ) ?? throw ChannelUnavailable::for('draw the status', 'this server has no Imagick (php-imagick)');

                return Response::binary($png, 'image/png');
            }
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }

        return Response::document([
            'data' => [
                'type' => 'monitor',
                'id' => $found->nick(),
                'attributes' => $monitor,
                'links' => ['self' => $instance],
            ],
        ]);
    }

    /**
     * The health of a grid: every address its config gives and what the grid says of itself, asked (and the ones that are expected
     * and not given, told), the PHP extensions it needs, the files it reads. `level` is `error` when something does not answer as it
     * should, `ok` when all that is asked does, `none` when there is nothing to ask. A check is asked at the moment of the request, it
     * takes the time of the slowest address.
     */
    public function health(string $grid): Response
    {
        $instance = self::path('health', null, $grid);
        try {
            $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
            $config = ($this->config)($found);
            $groups = Health::answers(
                Health::grid([
                    'config' => $config,
                    'live' => $found->gridInfo(),
                    'login' => (string) $found->loginUrl(),
                ]),
            );
            $groups[] = ['file' => 'PHP', 'title' => '', 'rows' => Health::extensions()];
            $files = Health::files($config?->files() ?? []);
            $files === [] || ($groups[] = ['file' => 'files', 'title' => '', 'rows' => $files]);
        } catch (\Throwable $error) {
            return Response::problem(Problem::from($error, $instance));
        }
        $levels = array_column(array_merge(...array_column($groups, 'rows') ?: [[]]), 'level');

        return Response::document([
            'data' => [
                'type' => 'health',
                'id' => $found->nick(),
                'attributes' => [
                    'level' => in_array('error', $levels, true)
                        ? 'error'
                        : (in_array('ok', $levels, true)
                            ? 'ok'
                            : 'none'),
                    'groups' => $groups,
                ],
                'links' => ['self' => $instance],
            ],
        ]);
    }

    /**
     * What waits for the answer to be sent: a simulator that registers is read, then the tasks that are due run. The application calls
     * it once the response is on its way (a PSR-15 handler cannot).
     */
    public function finish(): void
    {
        while (($todo = array_shift($this->after)) !== null) {
            $todo();
        }
        $this->scheduler?->tick();
    }

    /**
     * The search database of a grid, with its tables.
     *
     * @return array{0:\OpenSim\Data\OSPDO,1:string,2:GridConfig} the database, the table of the regions, the config of the grid
     * @throws Failure 503 when the grid has no search database or it cannot be reached
     */
    private function searchDatabase(string $nick): array
    {
        $grid = Grid::find($this->environment, $nick) ?? throw new Failure("There is no grid called '$nick'", 404);
        $config = ($this->config)($grid) ?? throw new Failure("There is no config for the grid '$nick'", 404);
        $db = Connections::pdo($config, Database::Search);
        if ($db === null || !$db->connected) {
            throw new Failure('Bad configuration: the search database is not available', 503);
        }
        $key = (string) Connections::key($config, Database::Search);
        if (!isset(self::$prepared[$key])) {
            SearchTables::ensure($db);
            self::$prepared[$key] = true;
        }

        return [$db, \OpenSim\Models\Region::searchTable($db), $config];
    }

    /** Run a task now, whether it is due or not. */
    public function run(string $name): Response
    {
        $instance = "/tasks/$name/runs";
        if ($this->scheduler === null || !in_array($name, array_column($this->scheduler->status(), 'name'), true)) {
            return Response::problem(Problem::notFound("task called '$name'", $instance));
        }
        $result = $this->scheduler->run($name, true);

        return Response::document(
            [
                'data' => [
                    'type' => 'runs',
                    'id' => $name,
                    'attributes' => [
                        'task' => $name,
                        'status' => $result->status,
                        'message' => $result->message,
                        'duration' => $result->duration,
                    ],
                ],
            ],
            $result->ok() ? 200 : 500,
        );
    }

    /**
     * The options of a list from a query: the sort of JSON:API (`-name`) becomes the one of the models (`name desc`).
     *
     * @param array<string,mixed> $query
     * @return array{search?:string,sort?:string,filters?:array<string,string>,page?:int,perPage?:int}
     */
    public static function options(array $query): array
    {
        $options = [];
        isset($query['search']) && ($options['search'] = (string) $query['search']);
        if (isset($query['sort']) && $query['sort'] !== '') {
            $sort = (string) $query['sort'];
            $options['sort'] = str_starts_with($sort, '-') ? substr($sort, 1) . ' desc' : $sort;
        }
        isset($query['filter']) &&
            is_array($query['filter']) &&
            ($options['filters'] = array_map('strval', $query['filter']));
        isset($query['page']['number']) && ($options['page'] = (int) $query['page']['number']);
        isset($query['page']['size']) && ($options['perPage'] = (int) $query['page']['size']);

        return $options;
    }

    /** The address of a resource or of a list. */
    public static function path(string $type, ?string $id = null, ?string $grid = null): string
    {
        $base = $grid !== null ? '/grids/' . rawurlencode($grid) . "/$type" : "/$type";

        return $id !== null ? $base . '/' . rawurlencode($id) : $base;
    }

    /** @return ModelInterface|Response the resource, or the problem that says it is not there */
    private function find(string $type, string $id, ?string $grid, string $instance): ModelInterface|Response
    {
        $class = self::classOf($type, $grid);
        $model = match (true) {
            $class === null => null,
            isset(self::RESOURCES[$type]) => $class::find($this->environment, $id),
            default => $class::find($this->gridConfig((string) $grid), $id),
        };
        if ($model === null) {
            $known = isset(self::RESOURCES[$type]) || isset(self::GRID_RESOURCES[$type]);

            return Response::problem(
                Problem::notFound(
                    $known ? rtrim($type, 's') . " called '$id'" : "kind of resource called '$type'",
                    $instance,
                ),
            );
        }

        return $model;
    }

    /** The config of a grid of this machine. */
    private function gridConfig(string $nick): GridConfig
    {
        $grid = Grid::find($this->environment, $nick) ?? throw NotFound::for("grid called '$nick'");

        return ($this->config)($grid) ?? throw NotFound::for("config of the grid '$nick' on this machine");
    }

    /** The model class of a type of resource, asked with a grid or without; null when the type is not one of those. */
    private static function classOf(string $type, ?string $grid): ?string
    {
        return $grid === null ? self::RESOURCES[$type] ?? null : self::GRID_RESOURCES[$type] ?? null;
    }

    /** @return list<string> the fonts the machine has to draw a text */
    public function fontNames(): array
    {
        return $this->renderer()->fonts();
    }

    private function renderer(): TextRendererInterface
    {
        return $this->texts ?? new ImagickTextRenderer();
    }

    /**
     * The destinations of the guide of a grid as resources, by id.
     *
     * @return array<string,array<string,mixed>>
     */
    private function destinations(string $grid): array
    {
        $found = Grid::find($this->environment, $grid) ?? throw NotFound::for("grid called '$grid'");
        $resources = [];
        foreach ($found->destinations(($this->config)($found)) as $category => $destinations) {
            foreach ($destinations as $destination) {
                $id = $base = Slug::slug("$category " . $destination['name']);
                for ($n = 2; isset($resources[$id]); $n++) {
                    $id = "$base-$n";
                }
                $resources[$id] = [
                    'type' => 'destinations',
                    'id' => $id,
                    'attributes' => [
                        'name' => $destination['name'],
                        'category' => (string) $category,
                        'url' => $destination['url'],
                    ],
                    'links' => ['self' => self::path('destinations', $id, $grid)],
                ];
            }
        }

        return $resources;
    }

    /** @return array{type:string,id:string,attributes:array<string,mixed>,links:array{self:string}} */
    private static function fontResource(string $name): array
    {
        return [
            'type' => 'fonts',
            'id' => $name,
            'attributes' => ['name' => $name],
            'links' => ['self' => self::path('fonts', $name)],
        ];
    }

    private function tasks(string $instance): Response
    {
        $tasks = $this->scheduler?->status() ?? [];

        return Response::document([
            'data' => array_map(static fn(array $task): array => self::taskResource($task), $tasks),
            'meta' => ['total' => count($tasks)],
            'links' => ['self' => $instance],
        ]);
    }

    private function task(string $name, string $instance): Response
    {
        foreach ($this->scheduler?->status() ?? [] as $task) {
            if ($task['name'] === $name) {
                return Response::document(['data' => self::taskResource($task), 'links' => ['self' => $instance]]);
            }
        }

        return Response::problem(Problem::notFound("task called '$name'", $instance));
    }

    /**
     * @param array<string,mixed> $task
     * @return array<string,mixed>
     */
    private static function taskResource(array $task): array
    {
        $name = $task['name'];
        unset($task['name']);

        return ['type' => 'tasks', 'id' => $name, 'attributes' => $task, 'links' => ['self' => "/tasks/$name"]];
    }

    /** @return array<string,mixed> a form: its fields, without what only the PHP side can do (the callbacks) */
    private function form(string $name): array
    {
        $fields = match ($name) {
            'grid' => Grid::fields(),
            'remote-grid' => Grid::remoteFields(),
            'simulator' => Simulator::fields(),
            'region' => Region::fields(),
        };
        $fields = array_map(
            static fn(array $field): array => array_diff_key($field, ['callback' => 1, 'validation' => 1]),
            $fields,
        );

        return [
            'type' => 'forms',
            'id' => $name,
            'attributes' => ['fields' => $fields],
            'links' => ['self' => "/forms/$name"],
        ];
    }

    /** @return array{type:string,id:string,attributes:array<string,mixed>,links:array{self:string}} */
    private static function resource(string $type, ModelInterface $model, ?string $grid = null): array
    {
        $id = (string) $model->id();
        $attributes = $model->detail();
        unset($attributes['id']);

        return [
            'type' => $type,
            'id' => $id,
            'attributes' => $attributes,
            'links' => ['self' => self::path($type, $id, $grid)],
        ];
    }
}
