<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Routes\NativeClusterRouterIntent;
use App\Domain\Routes\NativeClusterRouterProjection;
use App\Domain\Routes\NativeClusterRouterPublicationInput;
use App\Domain\Routes\NativeRoutePublicationArtifacts;
use App\Domain\Routes\RoutePublicationSites;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $manifest = tempnam(sys_get_temp_dir(), 'router-publication-agent-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    $this->beforeApplicationDestroyed(static fn () => unlink($manifest));
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root-fixture');
    $this->mock(ManagedUserAccountResolver::class)->shouldNotReceive('resolve');
    $this->cluster = Cluster::query()->create(['name' => 'Router phase capture', 'state' => 'active', 'tld' => 'cluster.test']);
    $this->old = routerPublicationNode('router-before', '10.44.0.11', $this->cluster);
    $this->candidate = routerPublicationNode('router-after', '10.44.0.12', $this->cluster);
    $this->workload = routerPublicationNode('router-workload', '10.44.0.13', $this->cluster);
    $this->dns = routerPublicationNode('router-dns', '10.44.0.14');
    $this->oldRole = $this->old->roles()->create(['role' => 'router', 'cluster_id' => $this->cluster->id, 'status' => 'active']);
    $this->candidateRole = $this->candidate->roles()->create(['role' => 'router', 'cluster_id' => $this->cluster->id, 'status' => 'provisioning']);
    $this->workload->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $this->dns->roles()->create(['role' => 'vpn', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Router phase', 'slug' => 'router-phase', 'repository_url' => 'https://example.test/router.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->workload->id, 'name' => 'development',
        'checkout_path' => '/srv/router-phase', 'status' => 'active', 'source_is_laravel' => false]);
    $this->route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $this->cluster->id, 'domain' => 'app.cluster.test',
        'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->operation = new Operation(['kind' => 'cluster.router_set', 'node_id' => $this->candidate->id,
        'input' => ['cluster_id' => $this->cluster->id, 'candidate_role_id' => $this->candidateRole->id]]);
});

function routerPublicationNode(string $name, string $address, ?Cluster $cluster = null): Node
{
    return Node::query()->create(['name' => $name, 'cluster_id' => $cluster?->id, 'user' => 'orbit', 'platform' => 'linux',
        'architecture' => 'x86_64', 'status' => 'active', 'wireguard_ip' => $address, 'public_ssh_host' => $address,
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'public-fixture-key']);
}

it('captures both Routers through DNS grace without selecting the candidate in the database', function (): void {
    $inventory = app(NativeClusterRouterIntent::class)->forOperation($this->operation);

    $capture = app(NativeClusterRouterPublicationInput::class)->capture($this->operation, $inventory);

    $rows = collect($capture['route_mutations']['forward']['publications'])->map(static fn (array $row): array => NativeRoutePublicationArtifacts::resolve($capture['route_mutations']['artifacts'], $row));
    $old = $rows->firstWhere('node_id', $this->old->id);
    $candidate = $rows->firstWhere('node_id', $this->candidate->id);
    $dns = $rows->firstWhere('node_id', $this->dns->id);
    expect($old['before']['caddy']['content'])->toContain('app.cluster.test')
        ->and($old['prepared']['caddy']['content'])->toContain('app.cluster.test')
        ->and($old['after']['caddy']['content'])->not->toContain('app.cluster.test');
    expect($candidate['before']['caddy']['content'])->not->toContain('app.cluster.test')
        ->and($candidate['prepared']['caddy']['content'])->toContain('app.cluster.test')
        ->and($candidate['after']['caddy']['content'])->toContain('app.cluster.test');
    expect($dns['before']['dns']['records'])->toContain('host-record=app.cluster.test,10.44.0.11')
        ->and($dns['prepared']['dns']['records'])->toContain('host-record=app.cluster.test,10.44.0.12')
        ->and($dns['after']['dns']['records'])->toContain('host-record=app.cluster.test,10.44.0.12');
    expect($capture['route_mutations']['forward']['cleanup'])->toContainEqual(['node_id' => $this->old->id, 'user' => 'orbit',
        'scope' => 'route-'.$this->route->id.'-router', 'agent_sha256' => str_repeat('a', 64), 'in_use' => false]);
    expect($candidate['control_node_id'])->toBe(209)->and($candidate['host_keys'])->toBe(['ssh-ed25519 public-fixture-key']);
    expect($this->oldRole->fresh()->status->value)->toBe('active')
        ->and($this->candidateRole->fresh()->status->value)->toBe('provisioning')
        ->and($this->cluster->fresh()->routerAssignment->id)->toBe($this->oldRole->id)
        ->and(Operation::query()->count())->toBe(0)->and(app(RoutePublicationSites::class)->current())->toBeNull();
});

it('keeps reverse publication and candidate cleanup available for native restoration', function (): void {
    $inventory = app(NativeClusterRouterIntent::class)->forOperation($this->operation);

    $capture = app(NativeClusterRouterPublicationInput::class)->capture($this->operation, $inventory);

    $restore = $capture['route_mutations']['restore'];
    expect($restore['cleanup'])->toContainEqual(['node_id' => $this->candidate->id, 'user' => 'orbit',
        'scope' => 'route-'.$this->route->id.'-router', 'agent_sha256' => str_repeat('a', 64), 'in_use' => false]);
    expect(collect($restore['certificates'])->contains(fn (array $leaf): bool => $leaf['node_id'] === $this->old->id
        && $leaf['scope'] === 'route-'.$this->route->id.'-router'))->toBeTrue();
    expect($capture['participant_node_ids'])->toContain(209, $this->old->id, $this->candidate->id, $this->workload->id, $this->dns->id);
});

it('captures destination-scoped LAN retirement with the selected source kept in each direction', function (): void {
    $this->old->update(['lan_ip' => '192.168.6.11']);
    $this->candidate->update(['lan_ip' => '192.168.6.12']);
    $this->workload->update(['lan_ip' => '192.168.6.13']);
    $inventory = app(NativeClusterRouterIntent::class)->forOperation($this->operation);

    $capture = app(NativeClusterRouterPublicationInput::class)->capture($this->operation, $inventory);

    $forward = $capture['route_mutations']['forward'];
    $restore = $capture['route_mutations']['restore'];
    $forwardRules = collect($forward['lan'])->firstWhere('node_id', $this->workload->id)['rules'];
    expect(array_column($forwardRules, 'source'))->toBe(['192.168.6.11', '192.168.6.12'])
        ->and(array_column($forwardRules, 'ensure'))->toBe(['present', 'present']);
    $retired = collect($forward['retire_lan'])->firstWhere('node_id', $this->workload->id)['rules'];
    expect(array_column($retired, 'source'))->toBe(['192.168.6.12', '192.168.6.11'])
        ->and(array_column($retired, 'ensure'))->toBe(['present', 'absent']);
    $restored = collect($restore['retire_lan'])->firstWhere('node_id', $this->workload->id)['rules'];
    expect(array_column($restored, 'source'))->toBe(['192.168.6.11', '192.168.6.12'])
        ->and(array_column($restored, 'ensure'))->toBe(['present', 'absent']);
});

it('captures unset by withdrawing Router sites and preserving the workload identity', function (): void {
    $this->candidateRole->delete();
    $this->operation->kind = 'cluster.router_unset';
    $this->operation->node_id = $this->old->id;
    $this->operation->input = ['cluster_id' => $this->cluster->id];
    $inventory = app(NativeClusterRouterIntent::class)->forOperation($this->operation);

    $capture = app(NativeClusterRouterPublicationInput::class)->capture($this->operation, $inventory);

    $forward = $capture['route_mutations']['forward'];
    $rows = collect($forward['publications'])->map(static fn (array $row): array => NativeRoutePublicationArtifacts::resolve($capture['route_mutations']['artifacts'], $row));
    expect($rows->firstWhere('node_id', $this->old->id)['after']['caddy']['content'])->not->toContain('app.cluster.test');
    expect($rows->firstWhere('node_id', $this->workload->id)['after']['caddy']['content'])->not->toContain('app.cluster.test');
    expect($forward['routes'][0]['node_ids'])->toBe([$this->workload->id]);
    expect($this->oldRole->fresh()->status->value)->toBe('active');
});

it('omits unrelated private sites from deltas and excludes their leaf scopes from cleanup', function (): void {
    $other = Route::query()->create(['kind' => 'custom_proxy', 'node_id' => $this->old->id, 'domain' => 'unrelated.private.test',
        'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $other->customProxy()->create(['node_id' => $this->old->id, 'upstream' => 'http://127.0.0.1:4788']);
    $other->publishSites();
    $inventory = app(NativeClusterRouterIntent::class)->forOperation($this->operation);

    $capture = app(NativeClusterRouterPublicationInput::class)->capture($this->operation, $inventory);

    $forward = $capture['route_mutations']['forward'];
    $old = collect($forward['publications'])->map(static fn (array $row): array => NativeRoutePublicationArtifacts::resolve($capture['route_mutations']['artifacts'], $row))->firstWhere('node_id', $this->old->id);
    foreach (['before', 'prepared', 'withdrawn', 'after'] as $phase) {
        expect($old[$phase]['caddy']['content'])->not->toContain('unrelated.private.test');
    }
    expect(array_column($forward['cleanup'], 'scope'))->not->toContain('route-'.$other->id);
});

it('starts with the full captured public intent and never renders a fresh participant callback', function (): void {
    $inventory = app(NativeClusterRouterIntent::class)->forOperation($this->operation);
    $capture = app(NativeClusterRouterPublicationInput::class)->capture($this->operation, $inventory);
    $this->operation->input = [...$this->operation->input, 'cluster_router' => $inventory, ...$capture];
    $expected = $this->operation->input;
    $this->candidate->update(['wireguard_ip' => '10.44.0.250']);

    $input = app(NativeClusterRouterProjection::class)->input($this->operation);

    expect($input)->toBe(['intent' => $expected, 'execution_secrets' => []])
        ->and($input['intent']['certificates'])->not->toBeEmpty()
        ->and($input['intent']['route_mutations'])->toHaveKeys(['forward', 'restore']);
});

it('captures official Router ingress families and preserves shared workload tuples at retirement', function (): void {
    foreach ([[$this->old, '192.168.6.11'], [$this->candidate, '192.168.6.12'], [$this->workload, '192.168.6.13']] as [$node, $address]) {
        $node->update(['lan_ip' => $address, 'wireguard_public_key' => 'public-wg-fixture']);
    }
    $this->old->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Old Router workload', 'slug' => 'old-router-workload', 'repository_url' => 'https://example.test/old-workload.git', 'root' => 'public']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->old->id, 'name' => 'development',
        'checkout_path' => '/srv/old-router-workload', 'status' => 'active', 'source_is_laravel' => false]);
    $route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $this->cluster->id, 'domain' => 'old-workload.cluster.test',
        'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    $route->update(['status' => 'active', 'sites_published' => true]);
    $inventory = app(NativeClusterRouterIntent::class)->forOperation($this->operation);

    $capture = app(NativeClusterRouterPublicationInput::class)->capture($this->operation, $inventory);

    $intent = $capture['cluster_router'];
    $plan = $intent['candidate']['firewall'];
    expect($plan)->toHaveKeys(['node_id', 'rules', 'migrate', 'legacy_router'])
        ->and($plan['legacy_router'])->toBeTrue()->and($plan['migrate'])->toBeTrue();
    $fromWorkload = array_values(array_filter($plan['rules'], fn (array $rule): bool => $rule['source'] === '192.168.6.13'));
    expect(array_map(fn (array $rule): array => array_intersect_key($rule, array_flip(['port', 'protocol', 'source', 'ensure'])), $fromWorkload))
        ->toBe([
            ['port' => 443, 'protocol' => 'tcp', 'source' => '192.168.6.13', 'ensure' => 'present'],
            ['port' => 53, 'protocol' => 'udp', 'source' => '192.168.6.13', 'ensure' => 'present'],
            ['port' => 53, 'protocol' => 'tcp', 'source' => '192.168.6.13', 'ensure' => 'present'],
        ]);
    foreach ($plan['rules'] as $rule) {
        expect($rule)->not->toHaveKeys(['destination', 'comment', 'in_interface', 'out_interface']);
    }
    $obsolete = $intent['old_nodes'][0]['firewall']['rules'];
    $shared = array_values(array_filter($obsolete, fn (array $rule): bool => $rule['port'] === 443 && $rule['source'] === '192.168.6.12'));
    expect(array_column($shared, 'ensure'))->toBe(['present']);
    $dns = array_values(array_filter($obsolete, fn (array $rule): bool => $rule['port'] === 53 && $rule['source'] === '192.168.6.12'));
    expect(array_column($dns, 'ensure'))->toBe(['absent', 'absent'])
        ->and($this->oldRole->fresh()->status->value)->toBe('active')
        ->and($this->candidateRole->fresh()->status->value)->toBe('provisioning');
});

it('executes only changed Router leaves and omits unchanged workload sites', function (): void {
    $capture = app(NativeClusterRouterPublicationInput::class)->capture($this->operation,
        app(NativeClusterRouterIntent::class)->forOperation($this->operation));

    $forward = $capture['route_mutations']['forward'];
    $restore = $capture['route_mutations']['restore'];
    expect(array_column($forward['prepare_certificates'], 'node_id'))->toBe([$this->candidate->id])
        ->and(array_column($restore['certificates'], 'node_id'))->toBe([$this->old->id])
        ->and($forward['certificates'])->toBe([])->and($restore['prepare_certificates'])->toBe([])
        ->and($capture['certificates'])->toHaveCount(2)
        ->and(array_column($capture['certificates'], 'node_id'))->not->toContain($this->workload->id)
        ->and(collect($forward['publications'])->map(static fn (array $row): array => NativeRoutePublicationArtifacts::resolve($capture['route_mutations']['artifacts'], $row))->firstWhere('node_id', $this->workload->id)['after']['caddy']['content'])
        ->not->toContain('app.cluster.test')
        ->and(array_column($forward['cleanup'], 'node_id'))->toBe([$this->old->id])
        ->and(array_column($restore['cleanup'], 'node_id'))->toBe([$this->candidate->id]);
});
