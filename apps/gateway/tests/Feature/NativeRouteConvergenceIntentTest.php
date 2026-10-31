<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationContribution;
use App\Domain\Operations\OperationStatus;
use App\Domain\Routes\NativeRouteConvergenceEnvironmentMaterial;
use App\Domain\Routes\NativeRouteConvergenceIntent;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
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
    $this->node = Node::query()->create(['name' => 'native-dev', 'public_ssh_host' => '192.0.2.11', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active]);
    $this->node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Native', 'slug' => 'native', 'repository_url' => 'https://example.test/native.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'native', 'checkout_path' => '/srv/native', 'branch' => 'main', 'source_is_laravel' => false, 'status' => InstanceState::SourceResolved]);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'domain' => 'native.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => RouteStatus::Pending]);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->manifest = tempnam(sys_get_temp_dir(), 'native-route-agent-');
    file_put_contents($this->manifest, json_encode(['commit' => str_repeat('b', 40), 'version' => '0.5.0', 'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('a', 64), 'url' => 'https://example.test/agent']]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($this->manifest, sys_get_temp_dir()));
    app()->instance(ManagedUserAccountResolver::class, new class implements ManagedUserAccountResolver
    {
        public function resolve(Node $node): ManagedUserAccount
        {
            return new ManagedUserAccount('orbit', 'orbit', '/home/orbit');
        }
    });
    $this->mock(LeafCertificateSigner::class)->shouldReceive('rootCertificate')->andReturn('public-root');
});

afterEach(function (): void {
    unlink($this->manifest);
});

it('captures exact workload and certificate participants without declaring child Operations', function (): void {
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    expect($intent['node_ids'])->toBe([$this->node->id])
        ->and($intent['workloads'])->toBe([['instance_id' => $this->instance->id, 'node_id' => $this->node->id, 'production' => false, 'certificate_scope' => 'app-instance-'.$this->instance->id]])
        ->and($intent['certificates'][0])->toMatchArray(['node_id' => $this->node->id, 'scope' => 'app-instance-'.$this->instance->id, 'domain' => 'native.test'])
        ->and($intent)->not->toHaveKeys(['stages', 'children', 'descriptors'])
        ->and(Operation::query()->count())->toBe(0);
});

it('rejects an Instance outside the admitted Route target set', function (): void {
    expect(fn () => app(NativeRouteConvergenceIntent::class)->forRoute($this->route, ['instance_id' => $this->instance->id + 1]))
        ->toThrow(ResourceOperationException::class);
});

it('projects native publication checkpoints without Gateway effect receipts', function (): void {
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = new Operation(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_convergence' => $intent, 'participant_node_ids' => [$this->node->id]]]);
    app(NativeRouteConvergenceProjection::class)->project($operation, ['checkpoint' => 'sites-published']);
    expect($this->route->fresh()->sites_published)->toBeTrue();
    $result = app(NativeRouteConvergenceProjection::class)->project($operation, ['ready' => true, 'route_id' => $this->route->id]);
    expect($result)->toBe(['ready' => true, 'route_id' => $this->route->id])->and($this->route->fresh()->status)->toBe(RouteStatus::Active);
});

it('refuses a native result for a different Route or an unbounded checkpoint', function (): void {
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = new Operation(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_convergence' => $intent, 'participant_node_ids' => [$this->node->id]]]);
    $projection = app(NativeRouteConvergenceProjection::class);
    expect(fn () => $projection->project($operation, ['ready' => true, 'route_id' => $this->route->id + 1]))->toThrow(ResourceOperationException::class);
    expect(fn () => $projection->project($operation, ['checkpoint' => 'sites-published', 'command' => 'anything']))->toThrow(ResourceOperationException::class);
    expect($this->route->fresh()->sites_published)->toBeFalse();
});

it('renders existing environment secrets only inside the owning Node private material', function (): void {
    $this->route->update(['status' => RouteStatus::Active]);
    $this->instance->update(['status' => InstanceState::Active, 'provisioning_step' => 'active', 'environment' => 'development', 'source_is_laravel' => true]);
    $this->instance->environmentValues()->create(['env_key' => 'APP_URL', 'env_value' => 'https://{{instance.domain}}']);
    $this->instance->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'private-existing-key']);
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = new Operation(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_convergence' => $intent, 'participant_node_ids' => [$this->node->id]]]);
    $writes = app(NativeRouteConvergenceEnvironmentMaterial::class)->forNode($operation, $this->node->id);
    expect($writes[0]['input']['content'])->toContain('private-existing-key')->toContain('https://native.test')
        ->and(json_encode($intent))->not->toContain('private-existing-key');
    expect(fn () => app(NativeRouteConvergenceEnvironmentMaterial::class)->forNode($operation, $this->node->id + 1))->toThrow(ResourceOperationException::class);
});

it('renders a fresh bound participant and rejects moved workloads', function (): void {
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = new Operation(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id,
        'input' => ['route_convergence' => $intent, 'participant_node_ids' => [$this->node->id], 'environment_fields' => []]]);
    $public = app(NativeRouteConvergenceProjection::class)->publicInput($operation, $this->node->id);
    expect($public['participant_node_id'])->toBe($this->node->id)
        ->and($public['caddy_publication']['node_id'])->toBe($this->node->id)
        ->and($public['workloads'][0]['instance_id'])->toBe($this->instance->id);
    expect(fn () => app(NativeRouteConvergenceProjection::class)->publicInput($operation, $this->node->id + 1))
        ->toThrow(ResourceOperationException::class);

    $this->route->targets()->delete();
    expect(fn () => app(NativeRouteConvergenceProjection::class)->publicInput($operation, $this->node->id))
        ->toThrow(ResourceOperationException::class);
});

it('withdraws initial sites before the native rollback republish and keeps recorded Route intent', function (): void {
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'status' => 'running', 'input' => ['route_convergence' => $intent], 'state' => []]);
    $projection = app(NativeRouteConvergenceProjection::class);
    $projection->project($operation, ['checkpoint' => 'sites-published']);
    $projection->project($operation, ['checkpoint' => 'rollback-initial']);

    expect($this->route->fresh()->sites_published)->toBeFalse()
        ->and($this->route->fresh()->status)->toBe(RouteStatus::Failed)
        ->and($this->route->targets()->pluck('instance_id')->all())->toBe([$this->instance->id])
        ->and($operation->fresh()->state['native_route_rollback'])->toBeTrue();
});

it('records native status and domain checkpoints atomically without receipt fields', function (): void {
    $existing = app(DirectOperation::class);
    $definitions = [];
    foreach (['route.create'] as $kind) {
        if ($existing->definition($kind) === null) {
            $definitions[$kind] = ['workflow' => 'operation-route-create', 'projection' => NativeRouteConvergenceProjection::class];
        }
    }
    $contribution = new class($definitions) implements DirectOperationContribution
    {
        public function __construct(private array $definitions) {}

        public function definitions(): array
        {
            return $this->definitions;
        }
    };
    app()->instance(DirectOperation::class, new DirectOperation([$contribution]));
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $runId = '66e6ef37-6d05-466b-97e8-b61b31e6388e';
    $operation = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'status' => 'running', 'input' => ['route_convergence' => $intent], 'state' => ['swamp_run_id' => $runId]]);
    $projection = app(NativeRouteConvergenceProjection::class);
    $data = ['run_id' => $runId, 'status' => 'running', 'started_at' => '2026-10-31T09:00:00Z', 'result' => ['checkpoint' => 'sites-published']];
    $projection->apply($operation, $data);
    expect($this->route->fresh()->sites_published)->toBeTrue()
        ->and($operation->fresh()->result)->toBe(['checkpoint' => 'sites-published']);
    $terminal = [...$data, 'status' => 'succeeded', 'finished_at' => '2026-10-31T09:00:01Z', 'result' => ['ready' => true, 'route_id' => $this->route->id]];
    $projection->apply($operation, $terminal);
    $projection->apply($operation, $terminal);
    expect($this->route->fresh()->status)->toBe(RouteStatus::Active)
        ->and($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->fresh()->state)->not->toHaveKeys(['steps', 'executor_runs', 'route_completed_stages']);
});

it('captures initial LAN rollback without withdrawing an already published Route allowance', function (): void {
    $cluster = Cluster::query()->create(['name' => 'lan-cluster', 'state' => 'active']);
    $router = Node::query()->create(['name' => 'lan-router', 'public_ssh_host' => '192.0.2.12', 'wireguard_ip' => '10.44.0.12',
        'lan_ip' => '10.10.0.12', 'cluster_id' => $cluster->id, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active']);
    $router->roles()->create(['role' => RoleName::Router, 'cluster_id' => $cluster->id, 'status' => 'active']);
    $this->node->update(['cluster_id' => $cluster->id, 'lan_ip' => '10.10.0.11']);
    $this->route->update(['cluster_id' => $cluster->id, 'node_id' => null]);
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route->fresh(), []);
    expect($intent['rollback_lan'])->toBe([['node_id' => $this->node->id, 'operation' => 'remove', 'route_id' => $this->route->id]])
        ->and(array_column($intent['rollback_removals'], 'scope'))->toBe(['route-'.$this->route->id.'-router']);
    $this->route->update(['status' => RouteStatus::Active]);
    expect(app(NativeRouteConvergenceIntent::class)->forRoute($this->route->fresh(), [])['rollback_lan'])->toBe([]);
});

it('preserves a serving Route when its no-effect create retry fails', function (): void {
    $this->route->update(['status' => RouteStatus::Active, 'sites_published' => true]);
    $before = $this->route->fresh()->getAttributes();
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, ['mode' => 'state_only']);
    $runId = '07bc1e88-150c-42b3-a58a-8ebddfb02e2f';
    $operation = Operation::query()->create(['kind' => 'route.create', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'status' => 'running', 'input' => ['route_convergence' => $intent], 'state' => ['swamp_run_id' => $runId]]);

    app(NativeRouteConvergenceProjection::class)->apply($operation, ['run_id' => $runId, 'status' => 'failed',
        'started_at' => '2026-10-31T10:00:00.000Z', 'finished_at' => '2026-10-31T10:00:01.000Z',
        'error_code' => 'operation.worker_unavailable', 'result' => ['route_id' => $this->route->id, 'error_code' => 'operation.worker_unavailable']]);

    expect($operation->fresh()->status)->toBe(OperationStatus::Failed)
        ->and($this->route->fresh()->getAttributes())->toBe($before);
    expect(fn () => app(NativeRouteConvergenceProjection::class)->project($operation, ['checkpoint' => 'rollback-initial']))->toThrow(ResourceOperationException::class);
});
