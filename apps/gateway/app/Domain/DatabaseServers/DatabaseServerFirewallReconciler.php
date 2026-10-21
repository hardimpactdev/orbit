<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Shared\LifecycleStatus;
use App\Models\DatabaseServer;
use App\Models\Node;
use Illuminate\Support\Facades\Cache;
use Throwable;

/** Projects the same explicit peer intent used by attachment selection. */
final readonly class DatabaseServerFirewallReconciler
{
    public function __construct(private DatabaseServerFirewall $firewall) {}

    /** @param array{lan_ip?: ?string, status?: LifecycleStatus} $override */
    public function reconcilePeer(Node $changingNode, array $override = [], bool $expand = false): void
    {
        $servers = DatabaseServer::query()->where('private_endpoints_managed', true)->with('node')->get();
        $affected = $servers->filter(fn (DatabaseServer $server): bool => $server->node_id === $changingNode->id
            || in_array($changingNode->id, $server->lan_node_ids ?? [], true));
        foreach ($affected->unique('node_id') as $server) {
            $node = $server->node;
            if ($node->status === LifecycleStatus::Active || $node->id === $changingNode->id) {
                Cache::lock('database-server:firewall:'.$node->id, 120)->block(65, fn () => $this->firewall->reconcile($node, $this->rules($node, nodeOverrides: [$changingNode->id => $override]), $expand));
            }
        }
    }

    /** @param array<int, array{lan_ip?: ?string, status?: LifecycleStatus}> $nodeOverrides
     * @param  array<int, list<int>>  $serverPeers
     * @return list<string>
     */
    public function rules(Node $node, ?int $excludingServer = null, array $nodeOverrides = [], array $serverPeers = []): array
    {
        $rules = [];
        $servers = DatabaseServer::query()->where('node_id', $node->id)->where('private_endpoints_managed', true)->orderBy('port')->get();
        foreach ($servers as $server) {
            if ($server->id === $excludingServer) {
                continue;
            }
            $port = $server->port;
            if (DatabaseServerLanPolicy::ipv4($node->wireguard_ip)) {
                $endpoint = "-d {$node->wireguard_ip}/32 -p tcp --dport {$port}";
                $rules[] = "{$endpoint} -i orbit -j RETURN";
                $rules[] = "{$endpoint} -i docker0 -j RETURN";
                $rules[] = "{$endpoint} -j DROP";
            }
            $lanAddresses = DatabaseServerLanPolicy::ipv4($node->lan_ip) ? [$node->lan_ip] : [];
            // Keep old Process bindings filtered until a create retry replaces its publications.
            $publications = $server->process?->runtime_config['ports'] ?? [];
            foreach (is_array($publications) ? $publications : [] as $spec) {
                $mapping = is_string($spec) ? DockerPublishedPort::parse($spec) : null;
                if ($mapping !== null && $mapping->publishedPort === $port && $mapping->bindAddress !== $node->wireguard_ip && DatabaseServerLanPolicy::ipv4($mapping->bindAddress)) {
                    $lanAddresses[] = $mapping->bindAddress;
                }
            }
            $sources = [];
            foreach (Node::query()->whereIn('id', [$node->id, ...($serverPeers[$server->id] ?? $server->lan_node_ids ?? [])])->get() as $peer) {
                $override = $nodeOverrides[$peer->id] ?? [];
                $status = $override['status'] ?? $peer->status;
                $address = array_key_exists('lan_ip', $override) ? $override['lan_ip'] : $peer->lan_ip;
                if ($status === LifecycleStatus::Active && DatabaseServerLanPolicy::ipv4($address)) {
                    $sources[] = $address;
                }
            }
            sort($sources);
            foreach (array_unique($lanAddresses) as $address) {
                $endpoint = "-d {$address}/32 -p tcp --dport {$port}";
                $rules[] = "{$endpoint} -i docker0 -j RETURN";
                foreach (array_unique($sources) as $source) {
                    $rules[] = "{$endpoint} -s {$source}/32 -j RETURN";
                }
                $rules[] = "{$endpoint} -j DROP";
            }
        }

        return array_values(array_unique($rules));
    }

    public function reconcile(Node $node, ?int $excludingServer = null): void
    {
        Cache::lock('database-server:firewall:'.$node->id, 120)->block(65, fn () => $this->firewall->reconcile($node, $this->rules($node, $excludingServer)));
    }

    /** @param list<int> $peerIds */
    public function replacePeers(DatabaseServer $server, array $peerIds): void
    {
        $peerIds = array_values(array_unique($peerIds));
        sort($peerIds);
        Cache::lock('database-server:firewall:'.$server->node_id, 120)->block(65, function () use ($server, $peerIds): void {
            $server->refresh();
            $current = $server->lan_node_ids ?? [];
            sort($current);
            if ($current === $peerIds) {
                return;
            }
            $node = $server->node;
            $this->firewall->reconcile($node, $this->rules($node, serverPeers: [$server->id => $peerIds]), expand: true);
            try {
                $server->update(['lan_node_ids' => $peerIds]);
            } catch (Throwable $exception) {
                $this->firewall->reconcile($node, $this->rules($node));
                throw $exception;
            }
            $this->firewall->reconcile($node, $this->rules($node));
        });
    }

    public function matches(Node $node): bool
    {
        return $this->firewall->matches($node, $this->rules($node));
    }
}
