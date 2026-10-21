<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DockerPublishedPort;
use App\Domain\Shared\LifecycleStatus;
use App\Models\DatabaseServer;
use App\Models\Node;
use Illuminate\Support\Facades\Cache;

/** Projects the same explicit peer intent used by attachment selection. */
final readonly class DatabaseServerFirewallReconciler
{
    public function __construct(private DatabaseServerFirewall $firewall) {}

    /** @param array<int, array{lan_ip?: ?string, status?: LifecycleStatus}> $nodeOverrides */
    public function reconcilePeers(array $nodeOverrides = [], bool $expand = false): void
    {
        $servers = DatabaseServer::query()->with('node')->get();
        foreach ($servers->unique('node_id') as $server) {
            $node = $server->node;
            if ($node->status === LifecycleStatus::Active) {
                Cache::lock('database-server:firewall:'.$node->id, 120)->block(65, fn () => $this->firewall->reconcile($node, $this->rules($node, nodeOverrides: $nodeOverrides), $expand));
            }
        }
    }

    /** @param array<int, array{lan_ip?: ?string, status?: LifecycleStatus}> $nodeOverrides
     * @return list<string>
     */
    public function rules(Node $node, ?int $excludingServer = null, array $nodeOverrides = []): array
    {
        $rules = [];
        $servers = DatabaseServer::query()->where('node_id', $node->id)->orderBy('port')->get();
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
            foreach (Node::query()->whereIn('id', [$node->id, ...($server->lan_node_ids ?? [])])->get() as $peer) {
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

    public function matches(Node $node): bool
    {
        return $this->firewall->matches($node, $this->rules($node));
    }
}
