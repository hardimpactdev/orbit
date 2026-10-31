<?php

declare(strict_types=1);

use App\Data\Routes\RouteTargetDispositionData;
use App\Data\Routes\SetRouteTargetsData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRouteTargetIntent;
use App\Domain\Routes\NativeRouteTargetProjection;
use App\Domain\Routes\RoutePreparedWorkloads;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;

beforeEach(function (): void {
    $cluster = Cluster::query()->create(['name' => 'native-target-pool', 'state' => 'active']);
    $this->router = Node::query()->create(['name' => 'target-router', 'platform' => 'linux', 'architecture' => 'x86_64', 'cluster_id' => $cluster->id,
        'wireguard_ip' => '10.44.0.2', 'status' => 'active', 'public_ssh_host' => '192.0.2.2']);
    $this->router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Native pool', 'slug' => 'native-pool', 'repository_url' => 'https://example.test/pool.git', 'root' => 'public']);
    $instances = [];
    foreach ([3, 4] as $number) {
        $node = Node::query()->create(['name' => 'target-worker-'.$number, 'cluster_id' => $cluster->id, 'platform' => 'linux', 'architecture' => 'x86_64',
            'wireguard_ip' => '10.44.0.'.$number, 'status' => 'active', 'public_ssh_host' => '192.0.2.'.$number]);
        $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
        $instances[] = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'prod-'.$number,
            'environment' => 'production', 'branch' => 'main', 'checkout_path' => '/srv/pool/'.$number, 'production_home' => '/srv/pool/'.$number,
            'production_user' => 'orbit-pool', 'source_is_laravel' => false, 'status' => 'active']);
    }
    [$this->first, $this->second] = $instances;
    $this->route = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'pool.private.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $this->sibling = Route::query()->create(['project_id' => $project->id, 'cluster_id' => $cluster->id, 'domain' => 'sibling.private.test',
        'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->first->id, 'position' => 0]);
    $this->sibling->targets()->create(['instance_id' => $this->second->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->sibling->update(['status' => 'active', 'sites_published' => true]);
    $manifest = tempnam(sys_get_temp_dir(), 'target-agent-');
    $this->manifest = $manifest;
    file_put_contents($manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    app()->instance(LeafCertificateSigner::class, new class implements LeafCertificateSigner
    {
        public function rootCertificate(): string
        {
            return 'public root';
        }

        public function sign(string $hostname, string $certificateRequest): string
        {
            throw new RuntimeException('Admission never signs a certificate.');
        }
    });
    $this->capture = fn (array $extra = []): array => app(NativeRouteTargetIntent::class)->forRoute($this->route->fresh(),
        ['mode' => 'targets', 'targets' => [$this->first->id, $this->second->id], 'dispositions' => [], ...$extra]);
    $this->operation = fn (array $intent): Operation => Operation::query()->create(['kind' => 'route.target_set', 'target_type' => 'route',
        'target_id' => $this->route->id, 'node_id' => $this->router->id, 'sequence' => 1, 'status' => 'running', 'input' => ['route_target' => $intent]]);
});

afterEach(function (): void {
    @unlink($this->manifest);
});

it('captures a full pool and both certificate phases without changing target associations', function (): void {
    $intent = ($this->capture)();
    expect($intent['prepared'])->toHaveCount(2)->and($intent['staging_certificates'])->toHaveCount(3)
        ->and($intent['live_certificates'])->toHaveCount(2)->and($intent['binding_only'])->toBeFalse()
        ->and($intent['workload_node_ids'])->toBe([$this->first->node_id, $this->second->node_id])
        ->and($this->route->targets()->pluck('instance_id')->all())->toBe([$this->first->id])
        ->and($this->sibling->targets()->pluck('instance_id')->all())->toBe([$this->second->id]);
    foreach ($intent['staging_certificates'] as $leaf) {
        expect($leaf['root_hash'])->toBe(hash('sha256', 'public root'))->and($leaf['agent_sha256'])->toBe(str_repeat('a', 64));
    }
});

it('captures singular binding source access as a native effect with no pool publication', function (): void {
    $intent = ($this->capture)(['mode' => 'target', 'instance_id' => $this->first->id]);
    expect($intent['binding_only'])->toBeTrue()->and($intent['prepared'])->toHaveCount(1)
        ->and($intent['staging_certificates'])->toBe([])->and($intent['live_certificates'])->toBe([])
        ->and($intent['dns_node_ids'])->toBe([])->and($intent['node_ids'])->toContain($this->first->node_id, $this->router->id);
});

it('projects prepared sites from immutable native input and removes them on rollback without child proof', function (): void {
    $operation = ($this->operation)(($this->capture)());
    $projector = app(NativeRouteTargetProjection::class);
    $projector->project($operation, ['checkpoint' => 'preparations-published']);
    expect((new RoutePreparedWorkloads)->sites($this->second->node_id))->toHaveCount(1)
        ->and($operation->fresh()->state['native_target_preparations'])->toBeTrue();
    $projector->project($operation, ['checkpoint' => 'rollback-preparations']);
    expect((new RoutePreparedWorkloads)->sites($this->second->node_id))->toBe([])
        ->and($this->route->targets()->pluck('instance_id')->all())->toBe([$this->first->id]);
});

it('commits the admitted reassignment once and keeps ordered targets on native replay', function (): void {
    $proposal = new SetRouteTargetsData([$this->second->id], [new RouteTargetDispositionData($this->first->id, $this->sibling->id)]);
    $intent = ($this->capture)($proposal->toIntent());
    $operation = ($this->operation)($intent);
    $projector = app(NativeRouteTargetProjection::class);
    $projector->project($operation, ['checkpoint' => 'database-committed']);
    $projector->project($operation, ['checkpoint' => 'database-committed']);
    expect($this->route->targets()->orderBy('position')->pluck('instance_id')->all())->toBe([$this->second->id])
        ->and($this->sibling->targets()->pluck('instance_id')->all())->toBe([$this->first->id])
        ->and($this->route->fresh()->target_set_step)->toBe('database-committed');
});

it('keeps a removal disposition attached until its lower native cleanup finishes', function (): void {
    $proposal = new SetRouteTargetsData([$this->second->id], [new RouteTargetDispositionData($this->first->id, remove: true)]);
    $intent = ($this->capture)($proposal->toIntent());
    $operation = ($this->operation)($intent);
    app(NativeRouteTargetProjection::class)->project($operation, ['checkpoint' => 'database-committed']);
    expect($intent['removals'])->toMatchArray([['instance_id' => $this->first->id, 'node_id' => $this->first->node_id,
        'project_id' => $this->first->project_id, 'checkout_path' => $this->first->checkout_path]])
        ->and($this->route->targets()->orderBy('position')->pluck('instance_id')->all())->toBe([$this->second->id, $this->first->id]);
});

it('projects bounded failure without rolling back a committed pool and completes without receipts', function (): void {
    $operation = ($this->operation)(($this->capture)());
    $projector = app(NativeRouteTargetProjection::class);
    $projector->project($operation, ['checkpoint' => 'database-committed']);
    $projector->project($operation, ['route_id' => $this->route->id, 'error_code' => 'route.target_set_failed']);
    expect($this->route->targets()->pluck('instance_id')->all())->toHaveCount(2)
        ->and($this->route->fresh()->error_code)->toBe('route.target_set_failed');
    $projector->project($operation, ['route_id' => $this->route->id, 'ready' => true]);
    expect($this->route->fresh()->target_set_intent)->toBeNull()->and($this->route->fresh()->target_set_step)->toBe('completed');
});

it('refuses foreign participants and unrelated checkpoint fields', function (): void {
    $operation = ($this->operation)(($this->capture)());
    expect(fn () => app(NativeRouteTargetIntent::class)->publicInput($operation, 999))->toThrow(ResourceOperationException::class)
        ->and(fn () => app(NativeRouteTargetProjection::class)->project($operation, ['checkpoint' => 'database-committed', 'proof' => true]))->toThrow(ResourceOperationException::class);
});

it('captures a completed no-op with a readable root and no preparation effects', function (): void {
    $intent = ($this->capture)(['mode' => 'state_only', 'targets' => [$this->first->id]]);
    expect($intent['noop'])->toBeTrue()->and($intent['prepared'])->toBe([])->and($intent['certificates'])->toBe([])
        ->and($intent['node_ids'])->toContain($this->first->node_id)->and($intent['workload_node_ids'])->toBe([]);
});

it('reuses saved native intent for forward recovery without capturing new certificate versions', function (): void {
    $intent = ($this->capture)();
    $operation = ($this->operation)($intent);
    $recovered = ($this->capture)(['mode' => 'targets_recover', 'recovery_operation_id' => $operation->id]);
    expect($recovered['recover_forward'])->toBeTrue()->and($recovered['staging_certificates'])->toBe($intent['staging_certificates'])
        ->and($recovered['live_certificates'])->toBe($intent['live_certificates']);
});
