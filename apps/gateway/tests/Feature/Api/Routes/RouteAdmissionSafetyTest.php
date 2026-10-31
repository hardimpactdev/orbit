<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitClusterRouterOperationAction;
use App\Actions\Routes\AdmitRouteOperationAction;
use App\Data\Routes\CreateRouteData;
use App\Data\Routes\UpdateRouteData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\ProductionWebRootManager;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Routes\RouteEnvironmentOperationLock;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\RouteForeignChildKinds;
use Tests\Support\RouteSwampGatewayFixture;

beforeEach(function (): void {
    bind_task_node_reachability();
    config(['swamp.run_token' => 'fixture.fake']);
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    $this->node = Node::query()->create(['name' => 'route-admission', 'public_ssh_host' => '192.0.2.5', 'wireguard_ip' => '10.44.0.5', 'user' => 'orbit', 'platform' => 'linux', 'status' => LifecycleStatus::Active]);
    $this->node->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    $project = Project::query()->create(['name' => 'Safety', 'slug' => 'safety', 'repository_url' => 'https://example.test/safety.git', 'root' => 'public']);
    $this->instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'production', 'environment' => 'production', 'production_user' => 'orbit-safety', 'production_home' => '/home/orbit-safety', 'checkout_path' => '/home/orbit-safety/releases/initial', 'branch' => 'main', 'source_is_laravel' => false, 'status' => 'active']);
    $this->route = Route::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'domain' => 'safe.private.test', 'provenance' => 'explicit', 'publication' => RoutePublication::Private, 'status' => 'pending']);
    $this->route->targets()->create(['instance_id' => $this->instance->id, 'position' => 0]);
    $this->route->update(['status' => 'active', 'sites_published' => true]);
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
});

it('registers every declared child with its owning family', function (): void {
    $definitions = OperationKinds::definitions();
    foreach ($definitions as $definition) {
        if (! is_array($definition)) {
            continue;
        }
        foreach ($definition['children'] ?? [] as $child) {
            expect($definitions)->toHaveKey($child['kind']);
        }
    }
});

it('refuses production publication before reserving a Route when PHP preparation is unavailable', function (): void {
    $roots = Mockery::mock(ProductionWebRootManager::class);
    $roots->shouldReceive('assertServable')->once();
    app()->instance(ProductionWebRootManager::class, $roots);
    expect(fn () => app(AdmitRouteOperationAction::class)->create(new CreateRouteData('new.private.test', RoutePublication::Private, instanceId: $this->instance->id, webRoot: 'apps/docs/public')))
        ->toThrow(ResourceOperationException::class, 'instance.prepare_served_web_roots');
    expect(Route::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('refuses a domain plan including its rollback before reserving a replacement when environment children are unavailable', function (): void {
    expect(fn () => app(AdmitRouteOperationAction::class)->update($this->route, new UpdateRouteData(true, 'new.private.test', false, null)))
        ->toThrow(ResourceOperationException::class, 'instance.sync_environment');
    expect($this->route->fresh()->replaced_by_route_id)->toBeNull()->and($this->route->fresh()->sites_published)->toBeTrue()
        ->and(Route::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('refuses production web-root withdrawal before withdrawing DNS or Caddy when PHP preparation is unavailable', function (): void {
    $this->route->update(['web_root' => 'apps/docs/public']);
    expect(fn () => app(AdmitRouteOperationAction::class)->destroy($this->route))
        ->toThrow(ResourceOperationException::class, 'instance.prepare_php_runtime');
    expect($this->route->fresh()->sites_published)->toBeTrue()->and($this->route->fresh()->status->value)->toBe('active')
        ->and(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('refuses Router assignment before recording a candidate when Node role children are unavailable', function (): void {
    $cluster = Cluster::query()->create(['name' => 'Router safety']);
    $this->node->update(['cluster_id' => $cluster->id]);
    expect(fn () => app(AdmitClusterRouterOperationAction::class)->set($cluster, $this->node))
        ->toThrow(ResourceOperationException::class, 'node.role_converge');
    expect(NodeRole::query()->where('cluster_id', $cluster->id)->where('role', RoleName::Router)->exists())->toBeFalse()
        ->and(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
});

it('fences a retained Instance environment writer until its Route parent is terminal', function (): void {
    $operation = Operation::query()->create(['kind' => 'route.update', 'target_type' => 'route', 'target_id' => $this->route->id,
        'node_id' => $this->node->id, 'input' => ['target_instance_ids' => [$this->instance->id]], 'status' => 'waiting', 'sequence' => 1]);
    $locks = app(InstanceEnvironmentOperationLock::class);
    expect($locks)->toBeInstanceOf(RouteEnvironmentOperationLock::class);
    $called = false;
    $write = function () use (&$called): void {
        $called = true;
    };
    expect(fn () => $locks->run([$this->instance->id], $write))->toThrow(ResourceOperationException::class, 'Route Operation owns');
    expect($called)->toBeFalse();
    $operation->update(['status' => 'succeeded']);
    $locks->run([$this->instance->id], $write);
    expect($called)->toBeTrue();
});

it('drives a production web-root publication through the real Swamp workflow and proven Gateway children', function (): void {
    $gateway = Node::query()->create(['name' => 'fixture-gateway', 'wireguard_ip' => '10.254.254.1', 'status' => 'active', 'public_ssh_host' => '192.0.2.1', 'platform' => 'linux']);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $gateway->accessibleNodes()->attach($this->node);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $roots = Mockery::mock(ProductionWebRootManager::class);
    $roots->shouldReceive('assertServable')->once();
    app()->instance(ProductionWebRootManager::class, $roots);
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([]));
    $locks = new class implements InstanceEnvironmentOperationLock
    {
        public bool $held = false;

        public array $ids = [];

        public function run(array $instanceIds, Closure $operation): mixed
        {
            $this->held = true;
            $this->ids[] = $instanceIds;
            try {
                return $operation();
            } finally {
                $this->held = false;
            }
        }
    };
    app()->instance(InstanceEnvironmentOperationLock::class, new RouteEnvironmentOperationLock($locks));
    $fixture = new RouteSwampGatewayFixture($this, httpRuns: true);
    $fixture->foreign->observe = static function () use ($locks): void {
        expect($locks->held)->toBeTrue();
    };

    $operation = $fixture->finish($this->postJson('/api/v1/routes', ['instance_id' => $this->instance->id,
        'domain' => 'docs.private.test', 'publication' => 'private', 'web_root' => 'apps/docs/public']));

    expect($operation->status->value)->toBe('succeeded')->and($operation->result['ready'])->toBeTrue()
        ->and(Route::query()->findOrFail($operation->target_id)->status->value)->toBe('active')
        ->and($locks->held)->toBeFalse()->and($locks->ids)->each->toBe([$this->instance->id])
        ->and(array_column($fixture->foreign->calls, 'kind'))->toContain('instance.prepare_served_web_roots', 'instance.sync_environment', 'instance.prepare_php_runtime');
});

it('takes the existing Instance environment lock before storing Route mutation intent', function (): void {
    app()->instance(InstanceEnvironmentOperationLock::class, new class implements InstanceEnvironmentOperationLock
    {
        public function run(array $instanceIds, Closure $operation): mixed
        {
            throw new ResourceOperationException('env.operation_busy', 'A deploy owns this Instance environment.', 409);
        }
    });
    expect(fn () => app(AdmitRouteOperationAction::class)->update($this->route, new UpdateRouteData(true, 'new.private.test', false, null)))
        ->toThrow(ResourceOperationException::class, 'A deploy owns');
    expect($this->route->refresh()->replaced_by_route_id)->toBeNull()->and(Operation::query()->count())->toBe(0)
        ->and($this->agents->calls)->toBe([]);
});

it('skips Metrics for target associations without calling the retained PHP reconciler at admission', function (string $mode): void {
    RouteForeignChildKinds::register();
    $this->node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $this->route->update(['status' => 'pending', 'sites_published' => false]);
    $this->instance->update(['status' => 'reserved']);
    $metrics = Mockery::mock(MetricsFleetReconciler::class);
    $metrics->shouldNotReceive('reconcile');
    app()->instance(MetricsFleetReconciler::class, $metrics);
    $admission = app(AdmitRouteOperationAction::class);
    $operation = $mode === 'set' ? $admission->setTarget($this->route, $this->instance->id) : $admission->clearTarget($this->route);
    expect($operation->status->value)->toBe('queued')
        ->and($operation->state['route_plan']['metrics'] ?? false)->toBeFalse()
        ->and($operation->state['route_plan']['stages'] ?? [])->not->toHaveKey('metrics-reconciled')
        ->and($operation->children()->exists())->toBeFalse()->and($this->agents->calls)->toBe([]);
})->with(['set', 'unset']);

it('does not announce a target change when its enclosing admission transaction rolls back', function (string $mode): void {
    $this->node->roles()->create(['role' => RoleName::Metrics, 'status' => LifecycleStatus::Active]);
    $this->route->update(['status' => 'pending', 'sites_published' => false]);
    if ($mode === 'set') {
        $this->route->targets()->delete();
    } else {
        $this->instance->update(['status' => 'reserved']);
    }
    Event::fake([RecordBroadcast::class]);
    $admission = app(AdmitRouteOperationAction::class);

    expect(fn () => DB::transaction(function () use ($mode, $admission): never {
        $mode === 'set' ? $admission->setTarget($this->route, $this->instance->id) : $admission->clearTarget($this->route);
        throw new ResourceOperationException('route.fixture_refused', 'Refuse before the enclosing admission commits.', 409);
    }))->toThrow(ResourceOperationException::class, 'Refuse before the enclosing admission commits.');

    Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated);
    expect($this->route->targets()->pluck('instance_id')->all())->toBe($mode === 'set' ? [] : [$this->instance->id])
        ->and(Operation::query()->count())->toBe(0)->and($this->agents->calls)->toBe([]);
})->with(['set', 'unset']);

it('announces an admitted target change only after its transaction commits', function (string $mode): void {
    $this->route->update(['status' => 'pending', 'sites_published' => false]);
    if ($mode === 'set') {
        $this->route->targets()->delete();
    } else {
        $this->instance->update(['status' => 'reserved']);
    }
    Event::fake([RecordBroadcast::class]);
    $admission = app(AdmitRouteOperationAction::class);

    $operation = DB::transaction(function () use ($admission, $mode): Operation {
        $operation = $mode === 'set' ? $admission->setTarget($this->route, $this->instance->id) : $admission->clearTarget($this->route);
        Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated);

        return $operation;
    });

    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated
        && $event->id === $this->route->id && count($event->data['targets']) === ($mode === 'set' ? 1 : 0));
    expect(Event::dispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated))->toHaveCount(1);
    expect($operation->fresh()->status->value)->toBe('queued')
        ->and($this->route->targets()->pluck('instance_id')->all())->toBe($mode === 'set' ? [$this->instance->id] : [])
        ->and($this->agents->calls)->toBe([]);
})->with(['set', 'unset']);
