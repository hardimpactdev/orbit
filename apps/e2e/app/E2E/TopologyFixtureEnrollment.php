<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\GuestCommand;
use App\E2E\Value\TopologyTarget;
use RuntimeException;

/** Calls the canonical fixture seeder with guest public identities; active rows are fixture intent. */
final readonly class TopologyFixtureEnrollment
{
    public function __construct(private IncusHost $host) {}

    /**
     * @param  array<string, string>  $addresses
     * @param  array<string, string>  $architectures
     * @param  array{url:string,commit:string,branch:string}  $repository  Public result of the fixture repository helper.
     * @return array{cluster:array{name:string},nodes:list<array<string,mixed>>,project:array<string,string>}
     */
    public function capture(TopologyTarget $target, array $addresses, array $architectures, array $repository): array
    {
        if ($repository['url'] !== 'https://repositories.orbit/seed.git' || $repository['branch'] !== 'main'
            || preg_match('/\A[0-9a-f]{40}\z/D', $repository['commit']) !== 1) {
            throw new RuntimeException('e2e.fixture_seed_repository_invalid.');
        }
        $commands = [];
        foreach ($target->instances() as $key => $instance) {
            $commands[$key] = ['instance' => $instance, 'command' => new GuestCommand(['/usr/local/bin/prepare-node.sh', 'fixture-facts'], 30)];
        }
        $results = $this->host->execAll($commands);
        $nodes = [];
        foreach ($target->recipe->nodes as $node) {
            $result = $results[$node->key];
            $facts = json_decode($result->stdout, true);
            if (! $result->successful() || ! is_array($facts)
                || array_keys($facts) !== ['wireguard_public_key', 'ssh_host_key_type', 'ssh_host_key']
                || ! is_string($facts['wireguard_public_key']) || preg_match('/\A[A-Za-z0-9+\/]{43}=\z/D', $facts['wireguard_public_key']) !== 1
                || $facts['ssh_host_key_type'] !== 'ssh-ed25519' || ! is_string($facts['ssh_host_key'])
                || ($architectures[$node->key] ?? null) !== 'x86_64'
                || filter_var($addresses[$node->key] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new RuntimeException('e2e.fixture_public_identity_invalid: '.$node->key.'.');
            }
            $nodes[] = [
                'key' => $node->key, 'name' => $node->key, 'platform' => 'linux', 'architecture' => $architectures[$node->key],
                'user' => 'orbit', 'home' => '/home/orbit', 'public_address' => $addresses[$node->key], 'public_ssh_port' => 22,
                'wireguard_ip' => $node->wireGuardAddress(), ...$facts,
                'roles' => $target->recipe->baseRoles($node),
                ...($node->tld === null ? [] : ['tld' => $node->tld]),
            ];
        }

        return [
            'cluster' => ['name' => 'proof-'.strtolower($target->issue)],
            'nodes' => $nodes,
            'project' => [
                'name' => 'Unused proof fixture', 'slug' => 'proof-fixture-'.strtolower($target->issue),
                'type' => 'node-package', 'repository_url' => $repository['url'],
                'default_branch' => $repository['branch'], 'root' => '.', 'source_access' => 'github_app',
            ],
        ];
    }

    /** @param array<string, mixed> $input
     * @return array{seeded:true,cluster_id:int,project_id:int,nodes:array<string,int>}
     */
    public function seed(TopologyTarget $target, array $input): array
    {
        $result = $this->host->exec($target->instance('gateway'), new GuestCommand(
            ['/usr/local/bin/converge-gateway.sh', 'seed'], 60, json_encode($input, JSON_THROW_ON_ERROR),
        ));
        $receipt = json_decode($result->stdout, true);
        if (! $result->successful() || ! is_array($receipt) || ($receipt['seeded'] ?? null) !== true
            || ! is_int($receipt['cluster_id'] ?? null) || $receipt['cluster_id'] < 1
            || ! is_int($receipt['project_id'] ?? null) || $receipt['project_id'] < 1
            || ! is_array($receipt['nodes'] ?? null)
            || array_keys($receipt['nodes']) !== $target->recipe->nodeKeys()) {
            throw new RuntimeException('e2e.fixture_seed_failed: no complete assigned Node map.');
        }
        $ids = [];
        foreach ($target->recipe->nodeKeys() as $key) {
            $id = $receipt['nodes'][$key];
            if (! is_int($id) || $id < 1 || in_array($id, $ids, true)) {
                throw new RuntimeException('e2e.fixture_seed_failed: no complete assigned Node map.');
            }
            $ids[$key] = $id;
        }

        return ['seeded' => true, 'cluster_id' => $receipt['cluster_id'], 'project_id' => $receipt['project_id'], 'nodes' => $ids];
    }
}
