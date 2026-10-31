<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\NativeRouteConvergenceIntent;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Domain\Routes\NativeRouteEnvironmentMaterial;
use App\Domain\Routes\NativeRouteUpdateIntent;
use App\Domain\Routes\NativeRouteUpdateProjection;
use App\Domain\Routes\RoutePublication;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
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

it('freezes old and desired roots and refuses unrelated public readers', function (): void {
    $intent = app(NativeRouteUpdateIntent::class)->forRoute($this->route, ['mode' => 'web_root', 'web_root' => 'docs/public']);
    $operation = new Operation(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_update' => $intent]]);
    expect($intent['previous']['web_root'])->toBeNull()->and($intent['desired']['web_root'])->toBe('docs/public')
        ->and($this->route->fresh()->web_root)->toBeNull();
    expect(fn () => app(NativeRouteUpdateIntent::class)->publicInput($operation, $this->node->id + 1))->toThrow(ResourceOperationException::class);
});

it('projects native publication checkpoints without Gateway effect receipts', function (): void {
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = new Operation(['kind' => 'route.converge', 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_convergence' => $intent]]);
    app(NativeRouteConvergenceProjection::class)->project($operation, ['checkpoint' => 'sites-published']);
    expect($this->route->fresh()->sites_published)->toBeTrue();
    $result = app(NativeRouteConvergenceProjection::class)->project($operation, ['ready' => true, 'route_id' => $this->route->id]);
    expect($result)->toBe(['ready' => true, 'route_id' => $this->route->id])->and($this->route->fresh()->status)->toBe(RouteStatus::Active);
});

it('restores an update root from its admitted snapshot and retains the native refusal', function (): void {
    $this->route->update(['status' => RouteStatus::Active]);
    $intent = app(NativeRouteUpdateIntent::class)->forRoute($this->route, ['mode' => 'web_root', 'web_root' => 'docs/public']);
    $operation = new Operation(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_update' => $intent]]);
    $projection = app(NativeRouteUpdateProjection::class);
    $projection->project($operation, ['checkpoint' => 'web-root-stored']);
    expect($this->route->fresh()->web_root)->toBe('docs/public');
    $projection->project($operation, ['checkpoint' => 'rollback-web-root-stored']);
    $projection->project($operation, ['route_id' => $this->route->id, 'error_code' => 'caddy.publication_failed']);
    expect($this->route->fresh()->web_root)->toBeNull()->and($this->route->fresh()->status)->toBe(RouteStatus::Active)
        ->and($this->route->fresh()->error_code)->toBeNull();
});

it('refuses a native result for a different Route or an unbounded checkpoint', function (): void {
    $intent = app(NativeRouteConvergenceIntent::class)->forRoute($this->route, []);
    $operation = new Operation(['kind' => 'route.converge', 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_convergence' => $intent]]);
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
    $operation = new Operation(['kind' => 'route.converge', 'target_type' => 'route', 'target_id' => $this->route->id, 'input' => ['route_convergence' => $intent, 'participant_node_ids' => [$this->node->id]]]);
    $writes = app(NativeRouteEnvironmentMaterial::class)->forNode($operation, $this->node->id);
    expect($writes[0]['input']['content'])->toContain('private-existing-key')->toContain('https://native.test')
        ->and(json_encode($intent))->not->toContain('private-existing-key');
    expect(fn () => app(NativeRouteEnvironmentMaterial::class)->forNode($operation, $this->node->id + 1))->toThrow(ResourceOperationException::class);
});
