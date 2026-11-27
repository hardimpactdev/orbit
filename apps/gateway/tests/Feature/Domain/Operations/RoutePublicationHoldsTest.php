<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Operations\CancelOperationAction;
use App\Data\Operations\OperationData;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Domain\Routes\NativeRouteConvergenceProjection;
use App\Domain\Routes\RoutePublicationSites;
use App\Domain\Routes\RouteUpdateEngineFlow;
use App\Domain\Shared\ResourceOperationException;
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

/** A container clone's create that captured its publication, with the shared-file lists of its Route plan. */
function cloneCreateWrite(array $serving, array $plan = []): Operation
{
    $operation = routeWrite($serving, 'instance.create');
    $operation->update(['input' => [...$operation->input, 'node_id' => $operation->node_id, 'route_id' => 1,
        'direct_provisioning' => ['container' => ['clone' => ['source' => 'instance-7']], 'route' => ['route_id' => 1]],
        'route_create' => [...['route_id' => 1, 'caddy' => [], 'php_files' => [], 'dns' => [], 'ingress' => [], 'lan' => []], ...$plan]]]);

    return $operation->refresh();
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

it('starts two removals on one Node side by side, while a publisher waits for both and a newer removal waits behind it', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [routeWrite([5, 6], 'instance.destroy'), routeWrite([5], 'instance.destroy')];

    // Each removal withdraws only its own sections under the Node lock: they share the Node.
    expect($holds->admit($first))->toBeTrue()->and($holds->admit($second))->toBeTrue()
        ->and(DB::table('route_withdrawal_holds')->where('node_id', 5)->pluck('operation_id')->all())->toBe([$first->id, $second->id]);

    // A publisher writes a whole rendered file: it waits for every removal on its Node, and a newer removal waits behind it.
    $publisher = routeWrite([5]);
    $third = routeWrite([5], 'instance.destroy');
    expect($holds->admit($publisher))->toBeFalse()->and($holds->admit($third))->toBeFalse()
        ->and($publisher->fresh()->status)->toBe(OperationStatus::Waiting)->and($third->fresh()->status)->toBe(OperationStatus::Waiting);

    $holds->releaseWithdrawn($first);
    runPromotion();
    expect($publisher->fresh()->status)->toBe(OperationStatus::Waiting);
    finish($second);
    runPromotion();
    expect($publisher->fresh()->status)->toBe(OperationStatus::Queued)->and(holder(5))->toBe($publisher->id)
        ->and($third->fresh()->status)->toBe(OperationStatus::Waiting);
    finish($publisher);
    runPromotion();
    expect($third->fresh()->status)->toBe(OperationStatus::Queued)
        ->and(DB::table('route_withdrawal_holds')->pluck('operation_id')->all())->toBe([$third->id]);
});

it('lets at most four removals among at most eight shared writers hold one Node, and starts the fifth removal when one has withdrawn', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $removals = array_map(static fn (): Operation => routeWrite([5], 'instance.destroy'), range(1, 4));
    expect(array_map(static fn (Operation $removal): bool => $holds->admit($removal), $removals))->toBe([true, true, true, true]);

    // Caddy-only clone creates fill the other four places, and a ninth shared writer leaves its address to its completion.
    $creates = array_map(static fn (): Operation => cloneCreateWrite([5]), range(1, 5));
    foreach ($creates as $create) {
        $holds->admit($create);
    }
    expect(array_map(static fn (Operation $create): bool => isset($create->fresh()->input['route_create']), $creates))->toBe([true, true, true, true, false])
        ->and(array_map(static fn (Operation $create): ?string => $create->fresh()->state['route_publication_reason'] ?? null, $creates))
        ->toBe([null, null, null, null, 'share_cap'])
        ->and(DB::table('route_withdrawal_holds')->where('node_id', 5)->count())->toBe(8);

    // A fifth removal waits, for the removal cap even once a create has gone, until a removal has withdrawn.
    $fifth = routeWrite([5], 'instance.destroy');
    expect($holds->admit($fifth))->toBeFalse();
    finish($creates[0]);
    $holds->release($creates[0]->id);
    runPromotion();
    expect($fifth->fresh()->status)->toBe(OperationStatus::Waiting);
    $holds->releaseWithdrawn($removals[0]);
    $holds->promoteAndStart();
    expect($fifth->fresh()->status)->toBe(OperationStatus::Queued)
        ->and(DB::table('route_withdrawal_holds')->where('node_id', 5)->count())->toBe(7);
});

it('gives a clone create whose publication writes more than the Caddyfile the Node alone: a second such create defers, a Caddy-only one too', function (array $plan): void {
    $holds = app(RoutePublicationHolds::class);
    $first = cloneCreateWrite([5], $plan);
    $second = cloneCreateWrite([5], $plan);
    $caddyOnly = cloneCreateWrite([5]);
    foreach ([$first, $second, $caddyOnly] as $create) {
        expect($holds->admit($create))->toBeTrue();
    }

    expect(DB::table('route_publication_holds')->pluck('operation_id')->all())->toBe([$first->id])
        ->and(DB::table('route_withdrawal_holds')->count())->toBe(0)
        ->and($first->fresh()->input)->toHaveKey('route_create')
        ->and($second->fresh()->input)->not->toHaveKey('route_create')
        ->and($caddyOnly->fresh()->input)->not->toHaveKey('route_create')
        ->and([$second->fresh()->state['route_publication_reason'], $caddyOnly->fresh()->state['route_publication_reason']])->toBe(['nodes_held', 'nodes_held']);
})->with([
    'a PHP-FPM pool' => [['php_files' => [['node_id' => 5, 'path' => '/etc/php/8.4/fpm/pool.d/orbit-scopes.conf']]]],
    'a private DNS record' => [['dns' => [['node_id' => 5]]]],
    'an Ingress site' => [['ingress' => [['node_id' => 5]]]],
    'a LAN rule' => [['lan' => [['node_id' => 5, 'port' => 443]]]],
]);

it('lets a Caddy-only clone create share a Node with a removal, and makes a whole-file publisher wait for both', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $removal = routeWrite([5], 'instance.destroy');
    $create = cloneCreateWrite([5]);
    expect($holds->admit($removal))->toBeTrue()->and($holds->admit($create))->toBeTrue()
        ->and($create->fresh()->input)->toHaveKey('route_create');
    $publisher = routeWrite([5]);
    expect($holds->admit($publisher))->toBeFalse();
    finish($removal);
    finish($create);
    $holds->release($removal->id);
    $holds->release($create->id);
    runPromotion();
    expect($publisher->fresh()->status)->toBe(OperationStatus::Queued);
});

it('makes a removal wait while a publisher holds its Node, and a step-scoped publisher wait while a removal holds it', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $publisher = routeWrite([5]);
    $removal = routeWrite([5], 'instance.destroy');
    expect($holds->admit($publisher))->toBeTrue()->and($holds->admit($removal))->toBeFalse();
    finish($publisher);
    runPromotion();
    expect($removal->fresh()->status)->toBe(OperationStatus::Queued);

    // A step hold of a provision is a publisher too: it is not granted beside the removal.
    $provision = routeWrite([5], 'instance.register');
    expect($holds->holdStep($provision, 0))->toBeNull();
    finish($removal);
    expect($holds->holdStep($provision, 0))->toBe([5]);
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
    [$provision, $other] = [routeWrite([5, 6], 'instance.register'), routeWrite([6], 'cluster.router_set')];

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
    [$first, $second] = [routeWrite([5], 'instance.register'), routeWrite([5], 'cluster.router_set')];
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
    $cancel = routeWrite([7, 9], 'cluster.router_set', other: [3]);
    $cancel->forceFill(['input' => ['workspace_routes' => [['route_mutations' => ['forward' => $cancel->input['route_lifecycle']['route_mutations']['forward']]]]]]);

    expect(RoutePublicationHolds::nodes($cancel))->toBe([7, 9])
        ->and(RoutePublicationHolds::holdsPerStep('instance.register'))->toBeTrue()
        ->and(RoutePublicationHolds::holdsPerStep('route.create'))->toBeFalse()
        ->and(RoutePublicationHolds::applies('instance.register'))->toBeFalse();
});

it('holds no Node per step for the task kinds whose runs publish no Route', function (string $kind): void {
    expect(RoutePublicationHolds::holdsPerStep($kind))->toBeFalse()
        ->and(RoutePublicationHolds::applies($kind))->toBeFalse();
})->with(['tasks.provision', 'tasks.cancel', 'tasks.maintenance_finish']);

it('takes no Node at admission for a kind that holds per step, so two provisions on one Node both start', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [routeWrite([5], 'instance.register'), routeWrite([5], 'instance.register')];

    expect($holds->admit($first))->toBeTrue()
        ->and($holds->admit($second))->toBeTrue()
        ->and(DB::table('route_publication_holds')->count())->toBe(0)
        ->and($second->refresh()->status)->toBe(OperationStatus::Queued);
});

it('renews a step hold that is asked for again after a lost answer, even while a Route write waits for its Node', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$provision, $waiter] = [routeWrite([5], 'instance.register'), routeWrite([5, 6])];
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
                'power_state' => 'stopped', 't3_port' => 3800 + $instance->id, 'container_server' => 't3']);
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

it('keeps two removals of one withdrawal slot apart on a Node: the second waits in the Gateway, not on a Swamp step name', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $removal = static function (int $routeId): Operation {
        $operation = routeWrite([5], 'instance.destroy');
        $input = $operation->input;
        $input['route_lifecycle']['route_mutations']['restore'] = ['routes' => [['route_id' => $routeId]]];
        $operation->update(['input' => $input]);

        return $operation->refresh();
    };
    // Routes 41 and 57 take slot 9 of route-withdraw-node on Node 5; Route 42 takes slot 10.
    [$first, $same, $other] = [$removal(41), $removal(57), $removal(42)];
    expect(RoutePublicationHolds::withdrawalSlot($first->input))->toBe(9)
        ->and(RoutePublicationHolds::withdrawalSlot($same->input))->toBe(9)
        ->and($holds->admit($first))->toBeTrue()
        ->and($holds->admit($same))->toBeFalse()
        ->and($same->fresh()->status)->toBe(OperationStatus::Waiting);
    // A removal of another slot waits behind the older waiter, as every newer write does; once the first has withdrawn, both start,
    // and a removal without a Route shares the Node beside them.
    expect($holds->admit($other))->toBeFalse();
    $holds->releaseWithdrawn($first);
    $holds->promoteAndStart();
    $none = routeWrite([5], 'instance.destroy');
    expect($same->fresh()->status)->toBe(OperationStatus::Queued)
        ->and($other->fresh()->status)->toBe(OperationStatus::Queued)
        ->and($holds->admit($none))->toBeTrue()
        ->and(DB::table('route_withdrawal_holds')->where('node_id', 5)->orderBy('operation_id')->pluck('operation_id')->all())
        ->toBe([$same->id, $other->id, $none->id]);
});

it('takes the withdrawal slot the way route-withdraw names its Node steps', function (): void {
    $parent = (string) file_get_contents(base_path('../../swamp/workflows/workflow-route-withdraw.yaml'));
    $child = (string) file_get_contents(base_path('../../swamp/workflows/workflow-route-withdraw-node.yaml'));

    expect($parent)->toContain('string(inputs.intent.route_mutations.restore.routes[0].route_id)')
        ->and($child)->toContain('string(int(inputs.intent.publication.route_id) % '.RoutePublicationHolds::WithdrawalSlots.')')
        ->and(RoutePublicationHolds::withdrawalSlot(['native_destroy' => ['route_mutations' => ['restore' => ['routes' => [['route_id' => 1150], ['route_id' => 3]]]]]]))->toBe(14)
        ->and(RoutePublicationHolds::withdrawalSlot(['native_destroy' => ['route_mutations' => ['restore' => ['routes' => []]]]]))->toBeNull();
});

/** A removal of an Instance, with or without a Route on the given Nodes. */
function instanceRemoval(int $instanceId, array $serving = [], string $kind = 'instance.destroy'): Operation
{
    $operation = routeWrite($serving, $kind);
    $operation->update(['input' => [...$operation->input, 'instance_id' => $instanceId]]);

    return $operation->refresh();
}

it('keeps an unregister and a destroy of one Instance slot on a Node apart, in either order', function (string $older, string $newer): void {
    $holds = app(RoutePublicationHolds::class);
    $started = startedOperations();
    // Both removal kinds name their release and hibernation steps by the slot: Instances 3206 and 3270 take slot 6.
    [$first, $same] = [instanceRemoval(3206, kind: $older), instanceRemoval(3270, [5], $newer)];
    expect(RoutePublicationHolds::destroySlot($same))->toBe(6)
        ->and($holds->admit($first))->toBeTrue()
        ->and($holds->admit($same))->toBeFalse()
        ->and($same->fresh()->status)->toBe(OperationStatus::Waiting)
        ->and(OperationData::fromModel($same->fresh())->holdWaitReason)->toBe('instance_slot');

    finish($first);
    runPromotion();
    expect($same->fresh()->status)->toBe(OperationStatus::Queued)->and($started->getArrayCopy())->toContain($same->id);
})->with([
    'unregister behind destroy' => ['instance.destroy', 'instance.unregister'],
    'destroy behind unregister' => ['instance.unregister', 'instance.destroy'],
]);

it('keeps two removals of one Instance slot on a Node apart for the whole run, with or without a Route, and tells why it waits', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $started = startedOperations();
    // Instances 3206 and 3270 take slot 6 of the container steps on the root Node; 3207 takes slot 7.
    [$first, $same, $other] = [instanceRemoval(3206), instanceRemoval(3270, [5]), instanceRemoval(3207)];
    expect(RoutePublicationHolds::destroySlot($first))->toBe(6)->and(RoutePublicationHolds::destroySlot($same))->toBe(6)
        ->and($holds->admit($first))->toBeTrue()
        ->and($holds->admit($same))->toBeFalse()
        ->and($holds->admit($other))->toBeTrue();
    $waiting = $same->fresh();
    expect($waiting->status)->toBe(OperationStatus::Waiting)
        ->and(OperationData::fromModel($waiting)->holdWaitReason)->toBe('instance_slot')
        ->and(OperationData::fromModel($other->fresh())->holdWaitReason)->toBeNull()
        // It claims no Node while it waits for its slot: a newer write on its Route Node passes.
        ->and(DB::table('route_withdrawal_holds')->where('operation_id', $same->id)->exists())->toBeFalse()
        ->and($holds->admit(routeWrite([5])))->toBeTrue();

    // Withdrawing is not enough: the slot is free only when the first removal has ended.
    $holds->releaseWithdrawn($first);
    $holds->promoteAndStart();
    expect($same->fresh()->status)->toBe(OperationStatus::Waiting);
    finish(Operation::query()->where('kind', 'route.create')->sole());
    finish($first);
    runPromotion();
    expect($same->fresh()->status)->toBe(OperationStatus::Queued)->and($started->getArrayCopy())->toContain($same->id)
        ->and(holder(5))->toBeNull()
        ->and(DB::table('route_withdrawal_holds')->where('operation_id', $same->id)->pluck('node_id')->all())->toBe([5]);
});

it('keeps the slot of a removal that timed out until Swamp confirms that its run stopped', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $first = instanceRemoval(3206);
    expect($holds->admit($first))->toBeTrue();
    // The Gateway gave up on the run, but Swamp may still run its container steps under slot 6.
    app(OperationStore::class)->change($first, ['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out', 'finished_at' => now(),
        'lock_key' => null, 'state' => [...$first->state, 'native_timeout' => []]]);
    $retry = instanceRemoval(3206);
    expect(RoutePublicationHolds::keepsNodes($first->fresh()))->toBeTrue()
        ->and($holds->admit($retry))->toBeFalse()
        ->and(OperationData::fromModel($retry->fresh())->holdWaitReason)->toBe('instance_slot');
    app(OperationStore::class)->change($first->fresh(), ['state' => [...$first->fresh()->state, 'native_cancel_confirmed_at' => now()->toIso8601String()]]);
    $holds->promoteAndStart();
    expect($retry->fresh()->status)->toBe(OperationStatus::Queued);
});

it('asks the waiters to start when a removal without a Route ends, so a removal of its slot starts at once', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$first, $same] = [instanceRemoval(3206), instanceRemoval(3270)];
    expect($holds->admit($first))->toBeTrue()->and($holds->admit($same))->toBeFalse();
    Queue::fake();
    finish($first);
    runPromotion();
    expect($same->fresh()->status)->toBe(OperationStatus::Queued);
});

it('keeps the slot of a timed-out removal that still holds its target lock, however long ago it ended', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $first = instanceRemoval(3206);
    expect($holds->admit($first))->toBeTrue();
    app(OperationStore::class)->change($first, ['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out',
        'finished_at' => now()->subMinutes(20), 'lock_key' => 'instance:3206', 'state' => [...$first->state, 'native_timeout' => []]]);
    $retry = instanceRemoval(3270);
    expect(RoutePublicationHolds::keepsNodes($first->fresh()))->toBeTrue()
        ->and($holds->admit($retry))->toBeFalse()
        ->and(OperationData::fromModel($retry->fresh())->holdWaitReason)->toBe('instance_slot');
});

it('says a removal admitted behind another Operation waits for it, not for an older write', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $completion = routeWrite([], 'instance.complete');
    $removal = instanceRemoval(3206);
    $removal->update(['input' => [...$removal->input, 'after_operation_id' => $completion->id]]);
    expect($holds->admit($removal->refresh()))->toBeFalse()
        ->and(OperationData::fromModel($removal->fresh())->holdWaitReason)->toBe('after_operation');
});

it('records why a removal waits: a held Node, the removal cap, the withdrawal slot', function (): void {
    $holds = app(RoutePublicationHolds::class);
    $publisher = routeWrite([5]);
    expect($holds->admit($publisher))->toBeTrue();
    $behind = instanceRemoval(1);
    $behind->update(['input' => [...$behind->input, 'route_lifecycle' => ['route_mutations' => ['forward' => ['publications' => [['node_id' => 5, 'serves_routes' => true]]]]]]]);
    expect($holds->admit($behind->refresh()))->toBeFalse()
        ->and(OperationData::fromModel($behind->fresh())->holdWaitReason)->toBe('nodes_held');
    finish($publisher);
    runPromotion();
    expect($behind->fresh()->status)->toBe(OperationStatus::Queued);

    $removals = array_map(static fn (int $id): Operation => instanceRemoval($id, [6]), [2, 3, 4, 5]);
    foreach ($removals as $removal) {
        expect($holds->admit($removal))->toBeTrue();
    }
    $fifth = instanceRemoval(7, [6]);
    expect($holds->admit($fifth))->toBeFalse()->and(OperationData::fromModel($fifth->fresh())->holdWaitReason)->toBe('removal_cap');

    $routed = static function (int $instanceId, int $routeId): Operation {
        $operation = instanceRemoval($instanceId, [8]);
        $input = $operation->input;
        $input['route_lifecycle']['route_mutations']['restore'] = ['routes' => [['route_id' => $routeId]]];
        $operation->update(['input' => $input]);

        return $operation->refresh();
    };
    [$a, $b] = [$routed(10, 41), $routed(11, 57)];
    expect($holds->admit($a))->toBeTrue()->and($holds->admit($b))->toBeFalse()
        ->and(OperationData::fromModel($b->fresh())->holdWaitReason)->toBe('removal_slot');
});

it('takes the Instance slot the way the destroy workflow names its container steps', function (): void {
    $workflow = (string) file_get_contents(base_path('../../swamp/workflows/workflow-operation-instance-destroy.yaml'));

    // Eight slot names: "Stop the container" takes the deletion's name of its slot, so a Node keeps its bound of names
    // (swamp/tests/instance_destroy_slot_names_test.ts).
    expect(substr_count($workflow, 'string(int(inputs.intent.instance_id) % '.RoutePublicationHolds::DestroySlots.')'))->toBe(8)
        ->and(RoutePublicationHolds::destroySlot(instanceRemoval(3206)))->toBe(3206 % RoutePublicationHolds::DestroySlots)
        ->and(RoutePublicationHolds::destroySlot(routeWrite([5])))->toBeNull();
});

/** A Route update admitted with serving Node `$serving` whose plan writes sites on `$sites` and the DNS catalog of VPN Node `$dns`. */
function engineUpdateWrite(int $serving, array $sites, int $dns): Operation
{
    $operation = routeWrite([$serving], 'route.update');
    $phase = ['site_nodes' => $sites];
    $operation->update(['input' => [...$operation->input, 'route_update' => ['route_id' => 1, 'result_route_id' => 2, 'plan' => [
        'publish' => $phase, 'retire' => $phase, 'cleanup' => [], 'dns' => [['node_id' => $dns, 'records' => "address=/new.example/10.44.0.2\n", 'catalog' => "{}\n"]]]]]]);

    return $operation->refresh();
}

it('leaves the DNS Node of a Swamp-run Route write unheld, as before the engine', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [engineUpdateWrite(5, [5], 8), engineUpdateWrite(6, [6], 8)];

    expect(RoutePublicationHolds::nodes($first))->toBe([5])
        ->and($holds->admit($first))->toBeTrue()
        ->and($holds->admit($second))->toBeTrue()
        ->and(holder(8))->toBeNull();
});

it('holds an engine-run update\'s site phase Nodes and VPN Node at its start, refuses a second, and frees them at its end', function (): void {
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [engineUpdateWrite(5, [5, 7], 8), engineUpdateWrite(6, [6], 8)];
    $holds->admit($first);
    $holds->admit($second);

    RouteUpdateEngineFlow::holdNodes($first);
    expect(holder(7))->toBe($first->id)->and(holder(8))->toBe($first->id)
        ->and(fn () => RouteUpdateEngineFlow::holdNodes($second))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('route.publication_held'));

    finish($first, OperationStatus::Failed);
    expect(holder(7))->toBeNull()->and(holder(8))->toBeNull();
    RouteUpdateEngineFlow::holdNodes($second);
    expect(holder(8))->toBe($second->id);

    Operation::query()->whereKey($second->id)->update(['status' => OperationStatus::Failed, 'finished_at' => now()]);
    expect($holds->sweep())->toBe(2)->and(holder(8))->toBeNull();
});

it('makes a Swamp rename whose phase plan writes a Node an engine update holds wait, in either start order', function (string $kind): void {
    $holds = app(RoutePublicationHolds::class);
    $phased = static function (int $serving, array $sites) use ($kind): Operation {
        $operation = routeWrite([], $kind);
        $key = $kind === 'route.update' ? 'route_update' : 'route_target';
        $operation->update(['input' => [...$operation->input, $key => ['route_id' => 3, 'result_route_id' => 4,
            'plan' => ['publish' => ['site_nodes' => [$serving]], 'retire' => ['site_nodes' => $sites], 'cleanup' => [], 'dns' => []]]]]);

        return $operation->refresh();
    };

    $engine = engineUpdateWrite(5, [5, 7], 8);
    expect($holds->admit($engine))->toBeTrue();
    RouteUpdateEngineFlow::holdNodes($engine);
    $swamp = $phased(9, [7]);
    expect(RoutePublicationHolds::nodes($swamp))->toBe([7, 9])
        ->and($holds->admit($swamp))->toBeFalse()
        ->and($swamp->refresh()->status)->toBe(OperationStatus::Waiting);

    finish($engine);
    finish($swamp, OperationStatus::Cancelled);
    $first = $phased(9, [7]);
    expect($holds->admit($first))->toBeTrue();
    $later = engineUpdateWrite(5, [5, 7], 8);
    expect($holds->admit($later))->toBeFalse();
})->with(['a Route update' => ['route.update'], 'a target change' => ['route.target_set']]);
