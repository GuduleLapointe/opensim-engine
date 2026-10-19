<?php

declare(strict_types=1);

namespace OpenSim\Provisioning;

use OpenSim\Result;
use OpenSim\Forms\FieldSet;
use OpenSim\Hypergrid\Destination;
use OpenSim\Models\Grid;
use OpenSim\Runtime\Environment;

/**
 * From the answers about a grid whose Robust runs elsewhere to the description a simulator joins it with. The answers are the
 * fields of Grid::remoteFields(), by name, or what a preset says of the grid (a grid there is a preset for is joined with what the
 * preset says, nothing else is asked). The grid tells its name and its nick itself (get_grid_info at its public port) when they are
 * left out.
 *
 * Nothing is asked and nothing is written here.
 */
final class RemoteGridPlanner
{
    /** @var array<string,mixed> */
    private array $context;

    private function __construct(array $context)
    {
        $this->context = $context + self::context();
    }

    /**
     * What the planner knows of the machine.
     *
     * @return array<string,mixed>
     */
    public static function context(): array
    {
        return [
            'environment' => null, // the Environment of the machine
            'presets' => [], // name => name of the grid
            'describe' => null, // Closure(string $preset): ?array, what a preset says of its grid (see Presets::describe())
            'says' => null, // Closure(string $host, int $port): array<string,string>, what the grid says of itself (default: asks it)
        ];
    }

    /** The planner of a machine. */
    public static function forMachine(Environment $environment): self
    {
        $presets = new Presets([], $environment->layout->etcRoot());

        return new self([
            'environment' => $environment,
            'presets' => $presets->names(),
            'describe' => static fn(string $name): ?array => $presets->describe($name),
        ]);
    }

    /** A planner with this context, for the tests and for the callers that know it. */
    public static function with(array $context): self
    {
        return new self($context);
    }

    /** The grids there is a preset for (preset => name of the grid). */
    public function presets(): array
    {
        return $this->context['presets'];
    }

    /**
     * The answers a preset gives, null when it does not say where its grid is.
     *
     * @return ?array<string,mixed>
     */
    public function presetAnswers(string $preset): ?array
    {
        $described = $this->context['describe'] !== null ? $this->context['describe']($preset) : null;

        return $described === null
            ? null
            : [
                'address' => "{$described['host']}:{$described['publicPort']}",
                'name' => $described['name'],
                'nick' => $described['nick'],
                'private_port' => $described['privatePort'],
                'hypergrid' => $described['hypergrid'],
            ];
    }

    /** The fields to join a grid. */
    public function fields(): FieldSet
    {
        return new FieldSet(Grid::remoteFields());
    }

    /**
     * What is proposed for each field: nothing but that the grid uses Hypergrid.
     *
     * @return array<string,mixed>
     */
    public function resolve(array $answers = []): array
    {
        return $answers + ['address' => '', 'name' => '', 'nick' => '', 'private_port' => '', 'hypergrid' => true];
    }

    /**
     * What is wrong with these answers, by field.
     *
     * @return array<string,string>
     */
    public function problems(array $answers = []): array
    {
        return $this->fields()->problems($this->resolve($answers));
    }

    /**
     * The grid these answers describe.
     *
     * @param string $preset the preset the answers come from: the grid is not asked what the preset says
     * @return array{grid:?Grid,error:?string,notes:list<string>,warnings:list<string>}
     */
    public function plan(array $answers, string $preset = ''): array
    {
        $environment = $this->context['environment'] ?? throw new \LogicException('The planner knows no environment');
        $layout = $environment->layout;
        $etcRoot = $layout->etcRoot();
        $v = $this->resolve($answers);
        $result = ['grid' => null, 'error' => null, 'notes' => [], 'warnings' => []];

        $address = trim((string) $v['address']);
        $login = Destination::loginParts($address);
        $host = $login['host'] ?? $address;
        // A port that is not given is the one of the scheme, as in any URL: the grid chooses its own
        $public = $login['port'] ?? (($login['scheme'] ?? 'http') === 'https' ? 443 : 80);

        // A preset says all that is needed, the grid is not asked
        $said = $preset !== '' ? [] : $this->says($environment, $host, $public);
        // The address it was reached at is not the grid: the login address it says is
        if (($official = Destination::loginParts((string) ($said['login'] ?? ''))) !== null) {
            $host = $official['host'];
            $public = $official['port'] ?? ($official['scheme'] === 'https' ? 443 : 80);
        }
        if ($said === [] && $preset === '') {
            $result['warnings'][] = sprintf(
                _(
                    'The grid did not answer at http://%s:%s/get_grid_info: check its address and its public port, or go on with what you know of it.',
                ),
                $host,
                $public,
            );
        }
        $name =
            trim((string) $v['name']) !== ''
                ? trim((string) $v['name'])
                : $said['gridname'] ?? ucfirst(explode('.', $host)[0]);
        // Letters and digits only, as typed (nick() would lower what is already a nick)
        $nick = (string) preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            trim((string) $v['nick']) !== '' ? (string) $v['nick'] : $said['gridnick'] ?? Slug::nick($name),
        );
        if ($nick === '') {
            $nick = Slug::nick($name);
        }

        $known = Grid::find($environment, $nick);
        if ($known?->robustIni() !== null) {
            $result['error'] = sprintf(
                _("A grid of this machine already has the nick '%s': it is its own Robust, not another one."),
                $nick,
            );

            return $result;
        }
        if ($known?->isRemote()) {
            $result['notes'][] = sprintf(_("Grid '%s' is already known here, its description is kept."), $nick);
            $result['grid'] = $known;

            return $result;
        }

        $result['grid'] = Grid::described($environment, [
            'remote' => true,
            'preset' => $preset,
            'nick' => $nick,
            'name' => $name,
            'slug' => Slug::slug($name),
            'directory' => "$etcRoot/grids/$nick",
            'hypergrid' => Result::isTrue($v['hypergrid']),
            'baseHostname' => $host,
            'publicPort' => $public,
            'privatePort' => trim((string) $v['private_port']) !== '' ? (int) $v['private_port'] : $public + 1,
            'coreDirectory' => $layout->coreDirectory(),
            'dataDirectory' => $layout->dataRoot() . "/$nick",
            'cacheDirectory' => $layout->cacheRoot() . "/$nick",
            'logsDirectory' => $layout->logsRoot(),
        ]);

        return $result;
    }

    /**
     * What a grid says of itself at its public port (get_grid_info).
     *
     * @return array<string,string> gridname, gridnick..., empty when it does not answer
     */
    private function says(Environment $environment, string $host, int $port): array
    {
        if ($this->context['says'] !== null) {
            return $this->context['says']($host, $port);
        }

        return Grid::announcement($environment, $host, $port);
    }
}
