<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\ClusterRouterReplacementStep;
use App\Domain\Routes\ClusterRouterTransition;
use App\Domain\Routes\NativeClusterRouterIntent;
use App\Domain\Routes\NativeClusterRouterProjection;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $cluster = Cluster::query()->create(['name' => 'Router transition']);
    $this->old = Node::query()->create(['name' => 'old-router', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'cluster_id' => $cluster->id]);
    $this->candidate = Node::query()->create(['name' => 'candidate-router', 'public_ssh_host' => '192.0.2.2', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'cluster_id' => $cluster->id]);
    $this->oldRole = $this->old->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $this->newRole = $this->candidate->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'provisioning']);
    $this->operation = new Operation(['kind' => 'cluster.router_set', 'target_id' => $cluster->id, 'input' => ['cluster_router' => ['candidate' => ['assignment_id' => $this->newRole->id], 'old_nodes' => [['assignment_id' => $this->oldRole->id]]]]]);
});

it('projects serving, authoritative selection and old cleanup without Gateway execution proof', function (): void {
    $projection = app(NativeClusterRouterProjection::class);
    $projection->project($this->operation, ['status' => 'running', 'result' => ['checkpoint' => 'router-serving']]);
    expect($this->newRole->fresh()->failed_step)->toBe(ClusterRouterReplacementStep::RouterCaddy->value)
        ->and($this->oldRole->fresh()->status)->toBe(LifecycleStatus::Active);
    $projection->project($this->operation, ['status' => 'running', 'result' => ['checkpoint' => 'router-selected']]);
    expect($this->newRole->fresh()->status)->toBe(LifecycleStatus::Active)
        ->and($this->oldRole->fresh()->status)->toBe(LifecycleStatus::Removing)
        ->and($this->oldRole->fresh()->failed_step)->toBeNull();
    $projection->project($this->operation, ['status' => 'running', 'result' => ['checkpoint' => 'old-router-withdrawn']]);
    expect($this->oldRole->fresh()->failed_step)->toBe(ClusterRouterTransition::OldRouterCleanup);
    $projection->project($this->operation, ['status' => 'succeeded', 'result' => ['ready' => true]]);
    expect($this->oldRole->fresh())->toBeNull()->and($this->newRole->fresh()->failed_step)->toBeNull();
});

it('keeps the authoritative candidate active after a later native DNS failure', function (): void {
    $this->oldRole->update(['status' => LifecycleStatus::Removing]);
    $this->newRole->update(['status' => LifecycleStatus::Active]);
    app(NativeClusterRouterProjection::class)->project($this->operation, ['status' => 'failed', 'error_code' => 'private_dns.activation_failed', 'result' => ['failed_step' => 'dns']]);
    expect($this->newRole->fresh()->status)->toBe(LifecycleStatus::Active)
        ->and($this->newRole->fresh()->error_code)->toBe('private_dns.activation_failed')
        ->and($this->oldRole->fresh())->not->toBeNull();
});

it('clears only admitted obsolete assignments after a native unset completes', function (): void {
    $this->operation->kind = 'cluster.router_unset';
    $this->operation->input = ['cluster_router' => ['candidate' => null, 'old_nodes' => [['assignment_id' => $this->oldRole->id]]]];
    app(NativeClusterRouterProjection::class)->project($this->operation, ['status' => 'succeeded', 'result' => ['ready' => true]]);
    expect($this->oldRole->fresh())->toBeNull()->and($this->newRole->fresh())->not->toBeNull();
});

it('captures the complete obsolete Router baseline for unset without changing assignment state', function (): void {
    $manifest = tempnam(sys_get_temp_dir(), 'cluster-agent-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            throw new RuntimeException('Router admission must not look up an account on a Node.');
        }
    });
    $this->old->update(['architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.9']);
    $this->candidate->update(['architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.18']);
    $this->operation->kind = 'cluster.router_unset';
    $this->operation->input = ['cluster_id' => $this->old->cluster_id];
    try {
        $intent = app(NativeClusterRouterIntent::class)->forOperation($this->operation);
        expect($intent['candidate'])->toBeNull()->and($intent['old_nodes'])->toHaveCount(2)
            ->and(array_column($intent['old_nodes'], 'assignment_id'))->toBe([$this->oldRole->id, $this->newRole->id])
            ->and($intent['old_nodes'][0]['firewall'])->toHaveKeys(['desired', 'remove', 'enable'])
            ->and($intent['old_nodes'][0]['router_lan'])->toBe(['desired' => [], 'expand' => false, 'prune' => true])
            ->and($intent['old_nodes'][0]['user'])->toBe('orbit')
            ->and($intent['old_nodes'][0])->not->toHaveKey('home')
            ->and($this->oldRole->fresh()->status)->toBe(LifecycleStatus::Active);
        $project = Project::query()->create(['name' => 'Router app', 'slug' => 'router-app', 'repository_url' => 'https://example.test/router.git', 'root' => 'public']);
        $target = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->old->id, 'name' => 'default', 'checkout_path' => '/srv/router/default', 'status' => 'active', 'source_is_laravel' => false]);
        $route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $this->old->cluster_id, 'domain' => 'router.private.test', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $target->id, 'position' => 0]);
        $route->update(['status' => 'active', 'sites_published' => true]);
        app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
        {
            public function rootCertificate(): string
            {
                return 'public-root-fixture';
            }
        });
        $this->operation->kind = 'cluster.router_set';
        $this->operation->node_id = $this->candidate->id;
        $this->operation->input = ['cluster_id' => $this->old->cluster_id, 'candidate_role_id' => $this->newRole->id];
        $setting = app(NativeClusterRouterIntent::class)->forOperation($this->operation);
        expect($setting['candidate']['assignment_id'])->toBe($this->newRole->id)
            ->and($setting['candidate']['user'])->toBe('orbit')
            ->and($setting['candidate'])->not->toHaveKey('home')
            ->and($setting['candidate']['prerequisites'])->toHaveKeys(['user', 'packages'])
            ->and($setting['candidate']['prerequisites'])->not->toHaveKeys(['home', 'group'])
            ->and($setting['leaves'])->toHaveCount(1)
            ->and($setting['leaves'][0]['user'])->toBe('orbit')
            ->and($setting['leaves'][0]['root_hash'])->toBe(hash('sha256', 'public-root-fixture'))
            ->and($setting['leaves'][0])->not->toHaveKey('home')
            ->and($setting['candidate']['firewall'])->toHaveKeys(['desired', 'remove', 'enable'])
            ->and($setting['old_nodes'])->toHaveCount(1)
            ->and($this->newRole->fresh()->status)->toBe(LifecycleStatus::Provisioning);
    } finally {
        unlink($manifest);
    }
});

it('authorizes only the captured root candidate and publication participants', function (): void {
    $this->operation->node_id = $this->candidate->id;
    $this->operation->input = ['cluster_router' => [
        'candidate' => ['node_id' => $this->candidate->id],
        'old_nodes' => [['node_id' => $this->old->id]],
        'workloads' => [['node_id' => 8001]], 'edge_node_ids' => [8002], 'dns_node_ids' => [8003],
    ]];
    $projection = app(NativeClusterRouterProjection::class);
    foreach ([$this->candidate->id, $this->old->id, 8001, 8002, 8003] as $nodeId) {
        $projection->assertPublicParticipant($this->operation, $nodeId);
    }
    expect(fn () => $projection->assertPublicParticipant($this->operation, 8004))->toThrow(ResourceOperationException::class);
});

it('rejects invalid configured participant accounts before capturing a Router inventory', function (array $attributes): void {
    $this->old->update($attributes);
    $this->operation->kind = 'cluster.router_unset';
    $this->operation->input = ['cluster_id' => $this->old->cluster_id];

    expect(fn () => app(NativeClusterRouterIntent::class)->forOperation($this->operation))
        ->toThrow(ResourceOperationException::class, 'Router participants require a configured Linux Node account.');
})->with([
    'invalid username' => [['user' => 'orbit;id']],
    'non-Linux participant' => [['platform' => 'macos']],
]);

it('records only bounded Router results and refuses an old checkpoint after completion', function (): void {
    $runId = 'c43ba393-aaf7-4166-a736-e421dd9d7e0a';
    $operation = Operation::query()->create(['kind' => 'cluster.router_set', 'target_type' => 'cluster_router',
        'target_id' => $this->candidate->cluster_id, 'node_id' => $this->candidate->id, 'input' => $this->operation->input,
        'status' => 'running', 'sequence' => 1, 'state' => ['swamp_run_id' => $runId]]);
    $projection = app(NativeClusterRouterProjection::class);
    $data = ['run_id' => $runId, 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['ready' => true, 'certificate_requests' => [['scope' => 'stale']], 'raw_output' => 'discard']];
    $result = $projection->apply($operation, $data);
    expect($result->result)->toBe(['ready' => true])->and($result->state)->not->toHaveKey('certificate_requests');
    $this->newRole->update(['status' => LifecycleStatus::Failed]);
    expect(fn () => $projection->apply($operation, [...$data, 'status' => 'running', 'result' => ['checkpoint' => 'router-selected']]))
        ->toThrow(ResourceOperationException::class);
    expect($this->newRole->fresh()->status)->toBe(LifecycleStatus::Failed);
});
