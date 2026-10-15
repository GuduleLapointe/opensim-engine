<?php

declare(strict_types=1);

/**
 * The default port mapper: the library kick-inside, with a gateway that records what it is asked.
 */

use Magicoli\KickInside\Gateway;
use Magicoli\KickInside\Mapping;
use Magicoli\KickInside\PortMapper;
use Magicoli\KickInside\Protocol;
use OpenSim\Network\PortMapping\KickInsideMapper;

/** A router that keeps its mappings in memory. */
final class FakeGateway implements Gateway
{
    /** @var list<string> */
    public array $calls = [];
    public ?string $refuse = null;
    public string $ip = '203.0.113.7';

    public function add(Mapping $mapping, bool $force = false): void
    {
        $key = $mapping->externalPort . '/' . strtolower($mapping->protocol->value);
        $this->calls[] = "add $key";
        if ($key === $this->refuse) {
            throw new \RuntimeException('refused');
        }
    }

    public function remove(int $externalPort, Protocol $protocol): void
    {
        $this->calls[] = "remove $externalPort/" . strtolower($protocol->value);
    }

    public function externalIp(): string
    {
        return $this->ip;
    }

    public function mappings(): array
    {
        return [];
    }
}

describe('kick-inside port mapper', function () {
    test('opens and closes a port by protocol, and gives the public address', function () {
        $gateway = new FakeGateway();
        $mapper = new KickInsideMapper(static fn(): PortMapper => new PortMapper($gateway, '192.168.1.10'));

        expect($mapper->prepare())
            ->toBeTrue()
            ->and($mapper->open(9021, 'udp'))
            ->toBeTrue()
            ->and($mapper->open(8002, 'tcp'))
            ->toBeTrue()
            ->and($mapper->close(9021, 'udp'))
            ->toBeTrue()
            ->and($mapper->address())
            ->toBe('203.0.113.7')
            ->and($gateway->calls)
            ->toBe(['add 9021/udp', 'add 8002/tcp', 'remove 9021/udp']);
    });

    test('says no, and does not break, when the router refuses a port', function () {
        $gateway = new FakeGateway();
        $gateway->refuse = '9021/udp';
        $mapper = new KickInsideMapper(static fn(): PortMapper => new PortMapper($gateway, '192.168.1.10'));

        expect($mapper->open(9021, 'udp'))->toBeFalse()->and($mapper->open(9020, 'tcp'))->toBeTrue();
    });

    test('cannot work, and says so, without a router; it has no address', function () {
        $mapper = new KickInsideMapper(static function (): PortMapper {
            throw new \RuntimeException('No UPnP Internet Gateway Device found.');
        });

        expect($mapper->prepare())
            ->toBeFalse()
            ->and($mapper->open(9021, 'udp'))
            ->toBeFalse()
            ->and($mapper->close(9021, 'udp'))
            ->toBeFalse()
            ->and($mapper->address())
            ->toBe('');
    });

    test('gives no address that is not one', function () {
        $gateway = new FakeGateway();
        $gateway->ip = 'unknown';
        $mapper = new KickInsideMapper(static fn(): PortMapper => new PortMapper($gateway, '192.168.1.10'));

        expect($mapper->address())->toBe('');
    });

    test('looks for the router once', function () {
        $found = 0;
        $gateway = new FakeGateway();
        $mapper = new KickInsideMapper(static function () use (&$found, $gateway): PortMapper {
            $found++;

            return new PortMapper($gateway, '192.168.1.10');
        });

        $mapper->prepare();
        $mapper->open(9021, 'udp');
        $mapper->address();

        expect($found)->toBe(1);
    });
});
