<?php

declare(strict_types=1);

namespace OpenSim\Network\PortMapping;

use Magicoli\KickInside\Port;
use Magicoli\KickInside\PortMapper;
use Magicoli\KickInside\Protocol;

/**
 * UPnP in PHP, with the library magicoli/kick-inside: it talks to the router itself (discovery, description, SOAP), no
 * command to run. The default of the port mapping.
 */
final class KickInsideMapper implements PortMapperInterface
{
    private ?PortMapper $mapper = null;

    /**
     * @param ?\Closure():PortMapper $discover finds the router (default: the discovery of the library)
     * @param ?string $internalClient the address (or the interface) of this machine the router forwards to; default, the one
     *                                the routing table gives
     */
    public function __construct(
        private readonly ?\Closure $discover = null,
        private readonly float $timeout = 3.0,
        private readonly ?string $internalClient = null,
    ) {}

    public function prepare(): bool
    {
        return $this->mapper() !== null;
    }

    public function open(int $port, string $proto): bool
    {
        return $this->apply(fn(PortMapper $mapper) => $mapper->open(self::port($port, $proto), description: 'OpenSim'));
    }

    public function close(int $port, string $proto): bool
    {
        return $this->apply(fn(PortMapper $mapper) => $mapper->close(self::port($port, $proto)));
    }

    public function address(): string
    {
        try {
            $address = $this->mapper()?->externalIp() ?? '';
        } catch (\Throwable) {
            return '';
        }

        return filter_var($address, FILTER_VALIDATE_IP) !== false ? $address : '';
    }

    /** The router, found once. */
    private function mapper(): ?PortMapper
    {
        if ($this->mapper === null) {
            try {
                $this->mapper =
                    $this->discover !== null
                        ? ($this->discover)()
                        : PortMapper::discover($this->timeout, $this->internalClient);
            } catch (\Throwable) {
                return null;
            }
        }

        return $this->mapper;
    }

    /** @param \Closure(PortMapper):mixed $action */
    private function apply(\Closure $action): bool
    {
        $mapper = $this->mapper();
        if ($mapper === null) {
            return false;
        }
        try {
            $action($mapper);
        } catch (\Throwable) {
            return false;
        }

        return true;
    }

    private static function port(int $port, string $proto): Port
    {
        return new Port($port, $port, strtolower($proto) === 'udp' ? Protocol::Udp : Protocol::Tcp);
    }
}
