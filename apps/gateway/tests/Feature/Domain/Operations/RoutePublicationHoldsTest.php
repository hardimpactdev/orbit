<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Domain\Routes\RoutePublicationSites;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Jobs\Operations\PromoteRouteWrites;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

beforeEach(fn () => Queue::fake());

/** The Operations the hold would have started, in order. */
function startedOperations(): ArrayObject
{
    $log = new ArrayObject;
    app()->instance(SwampDispatch::class, new class($log)
    {
        public function __construct(private readonly ArrayObject $log) {}

        public function start(Operation $operation): void
        {
            $this->log[] = $operation->id;
        }
    });

    return $log;
}

/** @param list<int> $serving @param list<int> $other */
function routeWrite(array $serving, string $kind = 'route.create', array $other = []): Operation
{
    $root = Node::query()->where('name', 'root')->first() ?? Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'root', 'status' => 'active',
        'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '10.44.0.2', 'wireguard_ip' => '10.44.0.2', 'user' => 'orbit']));
    $entries = [...array_map(static fn (int $id): array => ['node_id' => $id, 'serves_routes' => true], $serving),
        ...array_map(static fn (int $id): array => ['node_id' => $id, 'serves_routes' => false], $other)];

    return Operation::query()->create(['kind' => $kind, 'target_type' => 'route', 'target_id' => 1, 'node_id' => $root->id,
        'input' => ['route_lifecycle' => ['route_mutations' => ['forward' => ['publications' => $entries]]]],
        'status' => OperationStatus::Queued, 'state' => [], 'sequence' => 1, 'attempts' => 0]);
}

function finish(Operation $operation, OperationStatus $status = OperationStatus::Succeeded): void
{
    app(OperationStore::class)->change($operation, ['status' => $status, 'finished_at' => now(), 'lock_key' => null]);
}

/** What the worker does after a release: start the waiters that fit. */
function runPromotion(): void
{
    Queue::assertPushed(PromoteRouteWrites::class);
    (new PromoteRouteWrites)->handle(app(RoutePublicationHolds::class));
}

function holder(int $node): ?int
{
    return DB::table('route_publication_holds')->where('node_id', $node)->value('operation_id');
}

it('finds the Nodes that serve the sites at any path of the input, and none for another kind', function (): void {
    $destroy = routeWrite([7, 9], 'instance.destroy', other: [3]);
    $destroy->forceFill(['input' => ['native_destroy' => ['route_mutations' => ['forward' => $destroy->input['route_lifecycle']['route_mutations']['forward']]]]]);
    $deploy = routeWrite([7], 'instance.deploy');

    expect(RoutePublicationHolds::nodes($destroy))->toBe([7, 9])
        ->and(RoutePublicationHolds::nodes($deploy))->toBe([]);
});

it('starts the first write on a Node, makes the second wait, and lets another Node pass', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$first, $second, $other] = [routeWrite([5]), routeWrite([5]), routeWrite([6])];

    expect($holds->admit($first))->toBeTrue()
        ->and($holds->admit($second))->toBeFalse()
        ->and($holds->admit($other))->toBeTrue();
    expect($second->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and($second->state['hold_nodes'])->toBe([5])
        ->and(holder(5))->toBe($first->id)
        ->and(holder(6))->toBe($other->id);
});

it('starts the waiting writes oldest first when the holder finishes', function (): void {
    $started = startedOperations();
    $holds = app(RoutePublicationHolds::class);
    [$first, $second, $third] = [routeWrite([5]), routeWrite([5]), routeWrite([5, 6])];
    $holds->admit($first);
    $holds->admit($second);
    $holds->admit($third);

    finish($first);
    runPromotion();
    expect($second->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($second->started_at)->not->toBeNull()
        ->and($third->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and(holder(5))->toBe($second->id)
        ->and($started->getArrayCopy())->toBe([$second->id]);

    finish($second);
    runPromotion();
    expect($third->refresh()->status)->toBe(OperationStatus::Queued)
        ->and(holder(5))->toBe($third->id)
        ->and(holder(6))->toBe($third->id)
        ->and($started->getArrayCopy())->toBe([$second->id, $third->id]);

    finish($third);
    expect(DB::table('route_publication_holds')->count())->toBe(0);
});

it('does not let a newer write pass an older waiting one that shares a Node', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$holder, $wide, $narrow] = [routeWrite([5]), routeWrite([5, 6]), routeWrite([6])];
    $holds->admit($holder);
    $holds->admit($wide);

    expect($holds->admit($narrow))->toBeFalse()
        ->and(holder(6))->toBeNull();
});

it('starts the writes that a cancelled waiter was holding back', function (): void {
    $started = startedOperations();
    $holds = app(RoutePublicationHolds::class);
    [$holder, $wide, $narrow] = [routeWrite([5]), routeWrite([5, 6]), routeWrite([6])];
    $holds->admit($holder);
    $holds->admit($wide);
    $holds->admit($narrow);

    app(CancelOperationAction::class)->execute($wide);
    runPromotion();

    expect($wide->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($narrow->refresh()->status)->toBe(OperationStatus::Queued)
        ->and(holder(6))->toBe($narrow->id)
        ->and($started->getArrayCopy())->toBe([$narrow->id]);
});

it('keeps the Nodes of a timed-out write until Swamp confirms that its run stopped', function (): void {
    $started = startedOperations();
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [routeWrite([5]), routeWrite([5])];
    $holds->admit($first);
    $holds->admit($second);

    app(OperationStore::class)->change($first, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'operation.timed_out',
        'state' => ['native_timeout' => ['budget_seconds' => 60], 'native_cancel_requested_at' => now()->toISOString()]]);
    $holds->promoteAndStart();
    expect(holder(5))->toBe($first->id)
        ->and($second->refresh()->status)->toBe(OperationStatus::Waiting);

    app(OperationStore::class)->change($first, ['state' => [...$first->state, 'native_cancel_confirmed_at' => now()->toISOString()]]);
    runPromotion();
    expect($second->refresh()->status)->toBe(OperationStatus::Queued)
        ->and(holder(5))->toBe($second->id)
        ->and($started->getArrayCopy())->toBe([$second->id]);
});

it('gives the Nodes of a timed-out write back after 15 minutes without a confirmation', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $first = routeWrite([5]);
    $holds->admit($first);
    $first->forceFill(['status' => OperationStatus::Failed, 'finished_at' => now()->subMinutes(16), 'error_code' => 'operation.timed_out',
        'state' => ['native_timeout' => ['budget_seconds' => 60]]])->save();

    expect($holds->sweep())->toBe(1)
        ->and(holder(5))->toBeNull();
});

it('keeps the Nodes of a write that holds its target lock until the lock is freed', function (): void {
    startedOperations();
    $holds = app(RoutePublicationHolds::class);
    $first = routeWrite([5]);
    $first->forceFill(['lock_key' => 'route:1'])->save();
    $holds->admit($first);

    app(OperationStore::class)->change($first, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'operation.timed_out']);
    expect(holder(5))->toBe($first->id);

    app(OperationStore::class)->change($first, ['lock_key' => null]);
    expect(holder(5))->toBeNull();
});

it('frees the Nodes of a finished write that nothing released, and starts the waiter', function (): void {
    $started = startedOperations();
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [routeWrite([5]), routeWrite([5])];
    $holds->admit($first);
    $holds->admit($second);
    Operation::query()->whereKey($first->id)->update(['status' => OperationStatus::Failed, 'finished_at' => now()]);

    expect($holds->sweep())->toBe(1)
        ->and($second->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($started->getArrayCopy())->toBe([$second->id]);

    Operation::query()->whereKey($second->id)->delete();
    expect($holds->sweep())->toBe(1)
        ->and(DB::table('route_publication_holds')->count())->toBe(0);
});

it('never takes a started write for a waiter, so a second promotion cannot free its Nodes', function (): void {
    startedOperations();
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [routeWrite([5]), routeWrite([5])];
    $holds->admit($first);
    $holds->admit($second);
    $stale = Operation::query()->findOrFail($second->id);
    finish($first);
    $holds->promoteAndStart();

    $queue = new ReflectionMethod($holds, 'queue');
    expect($queue->invoke($holds, $stale))->toBeFalse()
        ->and(holder(5))->toBe($second->id);
});

it('leaves a waiting status that a native workflow reported alone', function (): void {
    $native = routeWrite([5], 'tasks.check');
    $native->forceFill(['status' => OperationStatus::Waiting, 'state' => ['swamp_run_id' => 'run-1'], 'created_at' => now()->subHour()])->save();
    $holds = app(RoutePublicationHolds::class);

    expect($holds->promote())->toBe([])
        ->and($native->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and(OperationRunBudgets::deadline($native))->not->toBeNull();
});

it('gives a held waiting write no deadline, and counts the budget of a started one', function (): void {
    $waiting = routeWrite([5]);
    $waiting->forceFill(['status' => OperationStatus::Waiting, 'state' => ['hold_nodes' => [5]], 'created_at' => now()->subHour()]);
    $running = routeWrite([5]);
    $running->forceFill(['status' => OperationStatus::Running, 'started_at' => now()]);

    expect(OperationRunBudgets::deadline($waiting))->toBeNull()
        ->and(OperationRunBudgets::deadline($running))->not->toBeNull();
});

it('holds the Nodes of a step-scoped kind only between its hold and its release, and never past the write that waits for them', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$provision, $other] = [routeWrite([5, 6], 'tasks.provision'), routeWrite([6], 'tasks.cancel')];

    expect($holds->holdStep($provision, 0))->toBe([5, 6])
        ->and(DB::table('route_publication_holds')->where('operation_id', $provision->id)->whereNotNull('lease_until')->count())->toBe(2)
        ->and($holds->holdStep($other, 0))->toBeNull()
        ->and($holds->holdStep($provision, 0))->toBe([5, 6]);

    $holds->releaseStep($provision);
    Queue::assertPushed(PromoteRouteWrites::class);

    expect(DB::table('route_publication_holds')->count())->toBe(0)
        ->and($holds->holdStep($other, 0))->toBe([6]);
});

it('does not grant a step hold past a Route write that waits for the same Node, and refuses a kind that holds per Operation', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$holder, $waiter, $register, $create] = [routeWrite([5]), routeWrite([5, 6]), routeWrite([6], 'instance.register'), routeWrite([6])];
    $holds->admit($holder);
    $holds->admit($waiter);

    expect($holds->holdStep($register, 0))->toBeNull()
        ->and($holds->holdStep($create, 0))->toBeNull();
});

it('frees a step hold when its lease has run out, and when its Operation ends', function (): void {
    startedOperations();
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [routeWrite([5], 'instance.register'), routeWrite([5], 'tasks.cancel')];
    $holds->holdStep($first, 0);
    DB::table('route_publication_holds')->where('operation_id', $first->id)->update(['lease_until' => now()->subSecond()]);

    expect($holds->holdStep($second, 0))->toBe([5])
        ->and(DB::table('route_publication_holds')->where('node_id', 5)->value('operation_id'))->toBe($second->id);

    finish($second);
    expect(DB::table('route_publication_holds')->count())->toBe(0);

    $holds->holdStep($first, 0);
    DB::table('route_publication_holds')->where('operation_id', $first->id)->update(['lease_until' => now()->subSecond()]);
    expect($holds->sweep())->toBe(1)
        ->and(DB::table('route_publication_holds')->count())->toBe(0);
});

it('finds the Nodes of a step-scoped kind in the Route mutations of its input', function (): void {
    $cancel = routeWrite([7, 9], 'tasks.cancel', other: [3]);
    $cancel->forceFill(['input' => ['workspace_routes' => [['route_mutations' => ['forward' => $cancel->input['route_lifecycle']['route_mutations']['forward']]]]]]);

    expect(RoutePublicationHolds::nodes($cancel))->toBe([7, 9])
        ->and(RoutePublicationHolds::holdsPerStep('tasks.provision'))->toBeTrue()
        ->and(RoutePublicationHolds::holdsPerStep('route.create'))->toBeFalse()
        ->and(RoutePublicationHolds::applies('tasks.provision'))->toBeFalse();
});

it('takes no Node at admission for a kind that holds per step, so two provisions on one Node both start', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [routeWrite([5], 'tasks.provision'), routeWrite([5], 'tasks.provision')];

    expect($holds->admit($first))->toBeTrue()
        ->and($holds->admit($second))->toBeTrue()
        ->and(DB::table('route_publication_holds')->count())->toBe(0)
        ->and($second->refresh()->status)->toBe(OperationStatus::Queued);
});

it('renews a step hold that is asked for again after a lost answer, even while a Route write waits for its Node', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$provision, $waiter] = [routeWrite([5], 'tasks.provision'), routeWrite([5, 6])];
    $holds->holdStep($provision, 0);
    $holds->admit($waiter);
    DB::table('route_publication_holds')->where('operation_id', $provision->id)->update(['lease_until' => now()->addSeconds(10)]);

    expect($waiter->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and($holds->holdStep($provision, 0))->toBe([5])
        ->and(DB::table('route_publication_holds')->where('operation_id', $provision->id)->value('lease_until'))->toBeGreaterThan(now()->addSeconds(200)->toDateTimeString());
});

it('renders both sites when a queued create gains the Node after another create completes', function (string $kind, bool $container): void {
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'beast', 'status' => 'active',
        'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit']));
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    if ($container) {
        app(AllocateInstanceBridgeAction::class)->execute($node);
    }
    $project = Project::query()->create(['name' => 'bench', 'slug' => 'bench', 'type' => 'laravel-app',
        'repository_url' => 'https://github.com/laravel/laravel.git', 'default_branch' => 'main', 'root' => 'public', 'php_version' => '8.5']);
    $admit = function (string $name, string $kind) use ($node, $project, $container): array {
        $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name,
            'checkout_path' => '/apps/bench/'.$name, 'status' => 'source_resolved', 'selected_php_version' => '8.5']);
        if ($container) {
            InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
                'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.0.'.($instance->id + 1), 'runtime_version' => 'runtime', 'agent_version' => 'agent',
                'power_state' => 'stopped', 't3_port' => 3800 + $instance->id]);
        }
        $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => $name.'.bench.test',
            'kind' => 'app', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $candidate = clone $route;
        $candidate->forceFill(['status' => 'active', 'sites_published' => true]);
        $sites = app(DevelopmentSiteRepository::class)->routeSites($candidate);
        $render = app(RoutePublicationSites::class)->render(collect($sites),
            fn () => app(NodeCaddyfileRenderer::class)->render($node));
        expect($render->buildable())->toBeTrue();
        $plan = ['route_id' => $route->id, 'domain' => $route->domain, 'caddy' => [['node_id' => $node->id, 'content' => $render->content]],
            'ingress' => [], 'leaves' => [['node_id' => $node->id, 'scope' => 'app-instance-'.$instance->id,
                'directory' => '/etc/caddy/orbit-certificates/app-instance-'.$instance->id.'/versions/admitted-version']], 'leaf_indexes' => [0]];
        $operation = routeWrite([$node->id], $kind);
        $operation->update(['target_type' => $kind === 'route.create' ? 'route' : 'instance',
            'target_id' => $kind === 'route.create' ? $route->id : $instance->id,
            'input' => [...$operation->input, 'route_id' => $route->id, 'instance_id' => $instance->id, 'project_id' => $project->id,
                'route_lifecycle' => [...$operation->input['route_lifecycle'], 'effectful' => true, 'public_activated' => false],
                'route_create' => $plan, 'direct_provisioning' => ['route' => $plan], 'certificates' => $plan['leaves']]]);

        return [$operation, $route, $instance];
    };
    [$first, $firstRoute, $firstInstance] = $admit('first', 'route.create');
    [$second, $secondRoute] = $admit('second', $kind);
    $input = $second->input;
    if ($kind === 'instance.create') {
        Instance::query()->findOrFail($second->target_id)->update(['status' => 'reserved']);
    }
    $holds = app(RoutePublicationHolds::class);
    expect($holds->admit($first))->toBeTrue()->and($holds->admit($second))->toBeFalse();
    expect($second->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and($second->state['swamp_run_id'] ?? null)->toBeNull()
        ->and($second->input['route_create']['caddy'][0]['content'])->not->toContain('https://first.bench.test');
    $started = startedOperations();
    $first->update(['state' => ['swamp_run_id' => 'first-run']]);
    app(NativeRouteConvergenceProjection::class)->apply($first,
        ['run_id' => 'first-run', 'status' => 'succeeded', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => []]);
    $firstInstance->update(['status' => 'active']);
    expect($firstRoute->refresh()->sites_published)->toBeTrue();
    $holds->promoteAndStart();

    $second->refresh();
    expect($started->getArrayCopy())->toBe([$second->id])
        ->and($second->input['route_create']['caddy'][0]['content'])->toContain('https://first.bench.test', 'https://second.bench.test')
        ->and($second->input['direct_provisioning']['route'])->toBe($second->input['route_create'])
        ->and($second->input['route_create']['leaves'])->toBe($input['route_create']['leaves'])
        ->and($second->input['certificates'])->toBe($input['certificates'])
        ->and($second->input['route_id'])->toBe($secondRoute->id)
        ->and($second->input['instance_id'])->toBe($input['instance_id']);
    if ($container) {
        expect($second->input['route_create']['caddy'][0]['content'])->toContain('reverse_proxy 10.253.0.2:8080', 'reverse_proxy 10.253.0.3:8080', 'https://first.bench.test:444', 'https://second.bench.test:444');
    }
})->with([
    'container create' => ['instance.create', true],
    'checkout create' => ['instance.create', false],
    'clone' => ['instance.clone', false],
    'Route create' => ['route.create', false],
]);

it('fails a promoted create and releases its Nodes if its admitted Route can no longer be rendered', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $first = routeWrite([5]);
    $second = routeWrite([5], 'instance.create');
    $second->update(['input' => [...$second->input, 'route_create' => ['route_id' => 987654, 'caddy' => [], 'ingress' => []]]]);
    $holds->admit($first);
    $holds->admit($second);
    $started = startedOperations();

    finish($first);
    $holds->promoteAndStart();

    expect($second->refresh()->status)->toBe(OperationStatus::Failed)
        ->and($second->error_code)->toBe('caddy.render_failed')
        ->and($second->state['swamp_run_id'] ?? null)->toBeNull()
        ->and(holder(5))->toBeNull()
        ->and($started->getArrayCopy())->toBe([]);
});
