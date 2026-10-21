<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Models\DatabaseServer;
use App\Models\Node;

final readonly class DatabaseServerLanPolicy
{
    public function eligible(DatabaseServer $server, Node $consumer): bool
    {
        return $this->host($server) !== null
            && $server->node->status === LifecycleStatus::Active
            && $consumer->status === LifecycleStatus::Active
            && self::ipv4($server->node->lan_ip)
            && self::ipv4($consumer->lan_ip)
            && ($server->node_id === $consumer->id || in_array($consumer->id, $server->lan_node_ids ?? [], true));
    }

    public function host(DatabaseServer $server): ?string
    {
        $address = $server->node->lan_ip;
        if (! $server->private_endpoints_managed || $server->status !== LifecycleStatus::Active || ! self::ipv4($address)) {
            return null;
        }
        $publications = $server->process?->runtime_config['ports'] ?? [];
        foreach (is_array($publications) ? $publications : [] as $spec) {
            $mapping = is_string($spec) ? DockerPublishedPort::parse($spec) : null;
            if ($mapping !== null && $mapping->bindAddress === $address && $mapping->publishedPort === $server->port) {
                return $address;
            }
        }

        return null;
    }

    /** @param list<int> $peerIds */
    public function validate(Node $node, array $peerIds): void
    {
        if (! self::privateIpv4($node->wireguard_ip) || (filled($node->lan_ip) && ! self::privateIpv4($node->lan_ip))) {
            throw new ResourceOperationException('validation.failed', 'Database publication requires private IPv4 addresses.', 422);
        }
        if ($peerIds === []) {
            return;
        }
        if (! self::ipv4($node->lan_ip)) {
            throw new ResourceOperationException('database.lan_unavailable', 'The server Node needs a LAN address.', 422);
        }
        foreach ($peerIds as $id) {
            $peer = Node::query()->find($id);
            if (! $peer instanceof Node || $peer->status !== LifecycleStatus::Active || ! self::ipv4($peer->lan_ip)) {
                throw new ResourceOperationException('database.lan_unavailable', 'Every LAN peer must be active with an IPv4 LAN address.', 422);
            }
        }
    }

    private static function privateIpv4(?string $address): bool
    {
        if (! is_string($address) || ! self::ipv4($address)) {
            return false;
        }

        return array_any(['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'], fn ($range) => Ipv4Subnet::from($range)->contains($address));
    }

    public static function ipv4(?string $address): bool
    {
        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
