<?php

declare(strict_types=1);

use App\Actions\Routes\AdmitRouteOperationAction;
use App\Data\Routes\UpdateRouteData;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationKinds;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;

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

    $changed = DB::transaction(function () use ($admission, $mode): Operation|Route {
        $changed = $mode === 'set' ? $admission->setTarget($this->route, $this->instance->id) : $admission->clearTarget($this->route);
        Event::assertNotDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated);

        return $changed;
    });

    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated
        && $event->id === $this->route->id && count($event->data['targets']) === ($mode === 'set' ? 1 : 0));
    expect(Event::dispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::RouteUpdated))->toHaveCount(1);
    // Setting a target starts its Route run after commit; clearing it is record-only (R1) and starts none.
    expect($mode === 'set' ? $changed->fresh()->status->value : Operation::query()->count())->toBe($mode === 'set' ? 'running' : 0)
        ->and($this->route->targets()->pluck('instance_id')->all())->toBe($mode === 'set' ? [$this->instance->id] : [])
        ->and($this->agents->calls)->toBe([]);
})->with(['set', 'unset']);
