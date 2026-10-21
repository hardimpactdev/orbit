<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
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
        if (! self::ipv4($node->wireguard_ip) || (filled($node->lan_ip) && ! self::ipv4($node->lan_ip))) {
            throw new ResourceOperationException('validation.failed', 'Database publication requires IPv4 addresses.', 422);
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

    public static function ipv4(?string $address): bool
    {
        return is_string($address) && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
