<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Operations\OperationRunBudgets;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Operations\RoutePublicationHolds;
use App\Infrastructure\Swamp\SwampDispatch;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

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
    expect($second->refresh()->status)->toBe(OperationStatus::Queued)
        ->and($third->refresh()->status)->toBe(OperationStatus::Waiting)
        ->and(holder(5))->toBe($second->id)
        ->and($started->getArrayCopy())->toBe([$second->id]);

    finish($second);
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

    expect($wide->refresh()->status)->toBe(OperationStatus::Cancelled)
        ->and($narrow->refresh()->status)->toBe(OperationStatus::Queued)
        ->and(holder(6))->toBe($narrow->id)
        ->and($started->getArrayCopy())->toBe([$narrow->id]);
});

it('keeps the Nodes of a timed-out write until its run is proven ended', function (): void {
    startedOperations();
    $holds = app(RoutePublicationHolds::class);
    [$first, $second] = [routeWrite([5]), routeWrite([5])];
    $first->forceFill(['lock_key' => 'route:1'])->save();
    $holds->admit($first);
    $holds->admit($second);

    app(OperationStore::class)->change($first, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => 'operation.timed_out']);
    expect(holder(5))->toBe($first->id)
        ->and($second->refresh()->status)->toBe(OperationStatus::Waiting);

    app(OperationStore::class)->change($first, ['lock_key' => null]);
    expect($second->refresh()->status)->toBe(OperationStatus::Queued)
        ->and(holder(5))->toBe($second->id);
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

it('gives a waiting write no deadline, and counts the budget of a started one', function (): void {
    $waiting = routeWrite([5]);
    $waiting->forceFill(['status' => OperationStatus::Waiting, 'created_at' => now()->subHour()]);
    $running = routeWrite([5]);
    $running->forceFill(['status' => OperationStatus::Running, 'started_at' => now()]);

    expect(OperationRunBudgets::deadline($waiting))->toBeNull()
        ->and(OperationRunBudgets::deadline($running))->not->toBeNull();
});
