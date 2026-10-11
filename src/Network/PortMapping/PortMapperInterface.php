<?php

declare(strict_types=1);

namespace OpenSim\Network\PortMapping;

/**
 * A way to make the ports of an instance reachable from outside (a NAT router), named by `PortMappingModule` in the `[Grid]`
 * of the config of the grid (see PortMappingService).
 */
interface PortMapperInterface
{
    /** Get what the mapper needs before it opens or closes ports (find the router). False when it cannot work. */
    public function prepare(): bool;

    /** Map a port of this machine to the same port outside. $proto is tcp or udp. */
    public function open(int $port, string $proto): bool;

    /** Remove the mapping of a port. */
    public function close(int $port, string $proto): bool;

    /** The public address of this machine, empty when it is not known. */
    public function address(): string;
}
