<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Forms\FieldSet;
use OpenSim\Models\Grid;
use OpenSim\Models\Region;
use OpenSim\Network\Ports;

/**
 * From the answers about a region to what a simulator plan says of it. The answers are the fields of Region::fields(), by name.
 * What is missing is what the setup proposes: a name nobody has, the roles the grid still lacks, the free place nearest to the
 * center of the grid by its rule of free blocks between regions, the next port of the block of the simulator.
 *
 * Nothing is asked and nothing is written here. A place that is taken is not refused: settle() says which one is free, and the
 * caller decides (a person is asked, a file takes the free one).
 */
final class RegionPlanner
{
    /** @var array<string,mixed> */
    private array $context;

    /** @var array<string,string> */
    private array $made = [];

    private function __construct(array $context)
    {
        $this->context = $context + self::context();
    }

    /**
     * What the planner knows of the grid and of the simulator.
     *
     * @return array<string,mixed>
     */
    public static function context(): array
    {
        return [
            'grid' => null,          // the Grid of the grid the region is in
            'optional' => false,     // the first region of a simulator, which may be left out
            'fixed' => false,        // a region that exists, which only moves
            'pool' => [],            // instance => title of the simulators that can take it
            'portOfSimulator' => null,     // Closure(string $instance): int, the HTTP port of a simulator of the pool
            'defaultName' => '',     // the name proposed first, if it fits
            'simulatorName' => '',         // the simulator, which gives its name to its first region
            'first' => true,         // no region is described for the simulator yet
            'httpPort' => 9000,      // the HTTP port of the simulator that takes it
            'taken' => [],           // lower case names of the regions of the grid
            'known' => [],           // places taken, "x,y" => true
            'ports' => [],           // ports used by the regions the grid knows
            'remoteHas' => null,     // Closure(string $name): ?bool, whether the Robust of a remote grid has the name (default: asks it)
            'freePlace' => null,     // Closure(int $x, int $y, ?string $ignored): array{0:int,1:int,2:bool}, the nearest free place and whether the grid answered
            'randomName' => null,    // Closure(Closure $problem): string
            'uuid' => null,          // Closure(): string
            'nextPort' => null,      // Closure(int $httpPort, array $taken): int, the next port for a region (default: Ports)
        ];
    }

    /**
     * The planner of a region of this simulator plan, in this grid.
     *
     * @param array<string,string> $pool the simulators that can take it (instance => title)
     * @param ?\Closure(string):int $portOfSimulator the HTTP port of a simulator of the pool
     */
    public static function forSimulator(SimulatorPlan $plan, Grid $grid, DatabaseService $database, bool $optional = false, array $pool = [], string $defaultName = '', ?\Closure $portOfSimulator = null): self
    {
        $registry = new GridRegistry($database);

        return new self([
            'grid' => $grid,
            'optional' => $optional,
            'pool' => $pool,
            'portOfSimulator' => $portOfSimulator,
            'defaultName' => $defaultName,
            'simulatorName' => $plan->simulatorName,
            'first' => (glob("{$plan->regionsDir()}/*.ini*") ?: []) === [],
            'httpPort' => $plan->httpPort,
            'taken' => $registry->names($grid),
            'known' => $registry->locations($grid),
            'ports' => $registry->ports($grid) ?? [],
        ]);
    }

    /** A planner with this context, for the tests and for the callers that know it. */
    public static function with(array $context): self
    {
        return new self($context);
    }

    /** The same planner for another simulator, which takes the region. */
    public function forPort(int $httpPort): self
    {
        return new self(['httpPort' => $httpPort] + $this->context);
    }

    /** The planner of a region that exists, which moves: its own place is free. */
    public function fixed(): self
    {
        return new self(['fixed' => true, 'optional' => false, 'pool' => []] + $this->context);
    }

    /** The roles a region can have in this grid (role => name): all of them, a grid can have several regions with the same one. */
    public function roleOptions(): array
    {
        $grid = $this->context['grid'];
        $options = [];
        foreach ($grid?->roles() ?? [] as $role) {
            $options[$role] = Region::label(Region::flagValue($role));
        }

        return $options;
    }

    /** The roles the grid still has no region for: the ones checked at first. @return list<string> */
    public function missingRoles(): array
    {
        return array_values(array_diff($this->context['grid']?->missingRoles() ?? [], [Region::flagName(Region::FALLBACK_REGION)]));
    }

    /** The fields of the region, with the checks that need to know the grid. */
    public function fields(): FieldSet
    {
        $fields = Region::fields([
            'optional' => $this->context['optional'],
            'roles' => $this->roleOptions(),
            'pool' => $this->context['pool'],
            'fixed' => $this->context['fixed'],
        ]);
        foreach ($fields as &$field) {
            if ($field['name'] === 'name') {
                $field['callback'] = fn(mixed $value): ?string => $this->nameProblem((string) $value);
            }
        }

        return new FieldSet($fields);
    }

    /**
     * What the region is for these answers.
     *
     * @param array<string,mixed> $answers by the names of the fields
     * @return array<string,mixed> create, name, roles (list), location, sim, port (a number)
     */
    public function resolve(array $answers = []): array
    {
        $c = $this->context;
        $simulator = (string) ($answers['sim'] ?? '+');
        $httpPort = $simulator !== '+' && $c['portOfSimulator'] !== null ? ($c['portOfSimulator'])($simulator) : $c['httpPort'];
        $problem = fn(string $name): ?string => $this->nameProblem($name);
        // What is not answered is worked out, and only then (the place of a region of a grid on another machine is asked to it)
        $place = fn(): string => implode(',', array_slice($this->freePlace(...$this->center()), 0, 2));

        return [
            'create' => $this->flag($answers['create'] ?? true),
            'name' => trim((string) ($answers['name'] ?? $this->defaultName($problem))),
            // Checked at first: the default region and the one of the visitors, when the grid has none yet (and not the fallback)
            'roles' => array_values((array) ($answers['roles'] ?? $this->missingRoles())),
            'location' => trim((string) ($answers['location'] ?? $place())),
            'sim' => $simulator,
            'port' => (int) ($answers['port'] ?? $this->nextPort($httpPort)),
        ];
    }

    /** The name proposed: the one asked for if it fits, the one of the simulator for its first region, else one made at random. */
    private function defaultName(\Closure $problem): string
    {
        $c = $this->context;
        if ($c['defaultName'] !== '' && $problem($c['defaultName']) === null) {
            return $c['defaultName'];
        }
        if ($c['first'] && $c['simulatorName'] !== '' && $problem($c['simulatorName']) === null) {
            return $c['simulatorName'];
        }

        // Made once: what is proposed is what is taken
        return $this->made['name'] ??= ($c['randomName'] ?? RandomName::make(...))($problem);
    }

    /**
     * What is wrong with these answers, by field.
     *
     * @return array<string,string>
     */
    public function problems(array $answers = []): array
    {
        $values = $this->resolve($answers);

        return $this->fields()->problems(array_intersect_key($answers, $values) + $values);
    }

    /**
     * The place of a region: the one asked, or the free place nearest to it by the rule of the grid. A region that moves frees the
     * place it holds.
     *
     * @return array{asked:string,free:string,moved:bool,unreachable:bool}
     */
    public function settle(string $asked, ?string $current = null): array
    {
        $ignored = $current !== null && ($place = LocationFinder::parse($current)) !== null ? LocationFinder::key($place[0], $place[1]) : null;
        [$x, $y] = LocationFinder::parse($asked) ?? $this->center();
        [$freeX, $freeY, $unreachable] = $this->freePlace($x, $y, $ignored);

        return ['asked' => "$x,$y", 'free' => "$freeX,$freeY", 'moved' => $freeX !== $x || $freeY !== $y, 'unreachable' => $unreachable];
    }

    /** What the simulator plan says of the region these answers describe: the place is the free one nearest to the place asked. */
    public function apply(SimulatorPlan $plan, array $answers = [], ?string $current = null): void
    {
        $v = $this->resolve($answers);
        if ($this->context['optional'] && !$v['create']) {
            $plan->createRegion = false;

            return;
        }
        $plan->createRegion = true;
        $plan->regionName = Slug::ascii($v['name']);
        $plan->regionRoles = $v['roles'];
        $plan->regionUuid = $this->context['uuid'] !== null ? ($this->context['uuid'])() : self::uuid();
        $plan->regionLocation = $this->settle($v['location'], $current)['free'];
        $plan->regionPort = $v['port'];
    }

    /** Why a name is refused, null when it is fine: not valid, or a region of the grid has it (a name is unique in a grid). */
    private function nameProblem(string $name): ?string
    {
        $name = Slug::ascii($name);
        if (($problem = RegionName::problem($name)) !== null) {
            return $problem;
        }
        $grid = $this->context['grid'];
        if (isset($this->context['taken'][strtolower(trim($name))])) {
            return 'The grid has a region of this name already.';
        }
        if ($grid?->isRemote() === true) {
            $has = $this->context['remoteHas'] !== null
                ? ($this->context['remoteHas'])(trim($name))
                : RobustGrid::hasRegion($grid->baseHostname(), $grid->privatePort(), trim($name));
            if ($has === true) {
                return 'The grid has a region of this name already.';
            }
        }

        return null;
    }

    /** @return array{0:int,1:int} */
    private function center(): array
    {
        return $this->context['grid']?->centerPlace() ?? [8002, 8002];
    }

    /** @return array{0:int,1:int,2:bool} the nearest free place, and whether the grid answered when it is on another machine */
    private function freePlace(int $x, int $y, ?string $ignored = null): array
    {
        if ($this->context['freePlace'] !== null) {
            return ($this->context['freePlace'])($x, $y, $ignored);
        }
        // A region that moves frees the place it holds
        $known = $this->context['known'];
        if ($ignored !== null) {
            unset($known[$ignored]);
        }
        $unreachable = false;
        [$freeX, $freeY] = Places::nearestFree($this->context['grid'], $known, $x, $y, $ignored, $unreachable);

        return [$freeX, $freeY, $unreachable];
    }

    /** The port of a region: the next one of the block of its simulator, else the next free one. */
    private function nextPort(int $httpPort): int
    {
        $taken = array_merge($this->context['ports'], [$httpPort]);
        if ($this->context['nextPort'] !== null) {
            return ($this->context['nextPort'])($httpPort, $taken);
        }

        $ports = new Ports($this->context['grid']->environment());

        return $ports->nextRegion($httpPort, $taken) ?? $ports->next($httpPort + 1, $taken);
    }

    private function flag(mixed $value): bool
    {
        return is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    /** A random UUID (version 4). */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
