<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Nodes\NodeRemovalOperationCompletion;
use App\Domain\Nodes\NodeRemovalPolicy;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\Fleet\FleetFixtures;

function removalProofOperation(): array
{
    $caller = FleetFixtures::node('remove-proof-caller');
    $node = FleetFixtures::node('remove-proof-source');
    $serving = FleetFixtures::node('remove-proof-serving');
    $project = Project::query()->create(['slug' => 'remove-proof', 'name' => 'Remove proof', 'repository_url' => 'https://github.com/example/remove-proof.git']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'domain' => 'remove-proof.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $intents = [
        ['key' => 'remove-begin', 'kind' => 'node.removal_begin', 'node_id' => $node->id, 'input' => []],
        ['key' => 'remove-residue', 'kind' => 'route.offline_residue_clean', 'node_id' => $serving->id, 'input' => ['removed_node_id' => $node->id]],
    ];
    $plan = array_map(static fn (array $intent): array => ['type' => 'child', ...$intent, 'optional' => false], $intents);
    $intents[] = ['key' => 'remove-restore', 'kind' => 'node.removal_restore', 'node_id' => $node->id, 'input' => []];
    $operation = Operation::query()->create(['kind' => 'node.remove', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['caller_node_id' => $caller->id, '_node_plan' => $plan, '_node_result' => ['id' => $node->id, 'name' => $node->name, 'removed' => true],
            '_node_failure_result' => ['id' => $node->id, 'name' => $node->name, 'removed' => false]],
        'state' => ['child_intents' => $intents, 'removal_intent' => ['id' => $node->id, 'offline' => true, 'prior_status' => 'active', 'public_key' => null,
            'route_rows' => app(NodeRemovalPolicy::class)->routeRows($node)]], 'status' => 'running', 'sequence' => 1, 'attempts' => 1]);

    return [$operation, $node, $route];
}

function removalProofChild(Operation $operation, string $key, string $status = 'succeeded'): Operation
{
    $intent = array_find($operation->state['child_intents'], static fn (array $intent): bool => $intent['key'] === $key);
    $identity = ['parent_operation_id' => $operation->id, 'key' => $key, 'kind' => $intent['kind'], 'node_id' => $intent['node_id'],
        'target_type' => 'node', 'target_id' => $intent['node_id'], 'input' => $intent['input'], 'run_constraints' => []];
    $snapshot = ['intent' => $intent, 'admitted' => $identity];
    $child = new Operation;
    $child->forceFill(['kind' => $intent['kind'], 'node_id' => $intent['node_id'], 'target_type' => 'node', 'target_id' => $intent['node_id'],
        'input' => $intent['input'], 'parent_operation_id' => $operation->id, 'child_key' => $key, 'child_admission' => $snapshot,
        'child_fingerprint' => OperationChildIntent::fingerprint($snapshot), 'run_constraints' => [], 'status' => $status,
        'error_code' => $status === 'failed' ? 'route.cleanup_failed' : null, 'result' => [], 'sequence' => 1, 'attempts' => 1])->save();

    return $child;
}

it('keeps the source Route relation while a cleanup child is pending', function (): void {
    [$operation, $node, $route] = removalProofOperation();
    removalProofChild($operation, 'remove-begin');
    removalProofChild($operation, 'remove-residue', 'running');
    $node->update(['status' => 'removing']);

    expect(fn () => app(NodeLifecycleOperationExecution::class)->result($operation))->toThrow(ResourceOperationException::class);

    $this->assertModelExists($node);
    $this->assertDatabaseHas('routes', ['id' => $route->id, 'node_id' => $node->id]);
});

it('deletes the captured nonactive Route and Node only after serving cleanup proof', function (): void {
    Event::fake([RecordBroadcast::class]);
    [$operation, $node, $route] = removalProofOperation();
    removalProofChild($operation, 'remove-begin');
    removalProofChild($operation, 'remove-residue');
    $node->update(['status' => 'removing']);
    $result = app(NodeLifecycleOperationExecution::class)->result($operation);

    DB::transaction(fn () => app(NodeRemovalOperationCompletion::class)->complete($operation, OperationOutcome::succeeded($result)));

    $this->assertModelMissing($node);
    $this->assertModelMissing($route);
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::NodeDeleted && $event->id === $node->id);
    expect($result['removed'])->toBeTrue();
});

it('preserves stamped child proof after successful Node deletion', function (): void {
    [$operation, $node] = removalProofOperation();
    removalProofChild($operation, 'remove-begin');
    removalProofChild($operation, 'remove-residue');
    $node->update(['status' => 'removing']);
    $result = app(NodeLifecycleOperationExecution::class)->result($operation);
    DB::transaction(fn () => app(NodeRemovalOperationCompletion::class)->complete($operation, OperationOutcome::succeeded($result)));
    $operation->update(['status' => 'succeeded', 'result' => $result]);

    expect(app(OperationChildren::class)->requireSucceeded($operation->fresh(), 'remove-begin')->result)->toBe([]);
});

it('refuses a newly attached Route before deleting the Node', function (): void {
    [$operation, $node, $route] = removalProofOperation();
    removalProofChild($operation, 'remove-begin');
    removalProofChild($operation, 'remove-residue');
    Route::query()->create(['project_id' => $route->project_id, 'node_id' => $node->id, 'domain' => 'new-remove-proof.test', 'publication' => 'private', 'provenance' => 'explicit', 'status' => 'pending']);
    $node->update(['status' => 'removing']);
    $result = app(NodeLifecycleOperationExecution::class)->result($operation);

    expect(fn () => DB::transaction(fn () => app(NodeRemovalOperationCompletion::class)->complete($operation, OperationOutcome::succeeded($result))))
        ->toThrow(ResourceOperationException::class);

    $this->assertModelExists($node);
    $this->assertModelExists($route);
});

it('requires the exact restoration child after serving cleanup refuses', function (bool $restoreFails): void {
    [$operation, $node, $route] = removalProofOperation();
    removalProofChild($operation, 'remove-begin');
    removalProofChild($operation, 'remove-residue', 'failed');
    $node->update(['status' => 'removing']);
    $execution = app(NodeLifecycleOperationExecution::class);
    expect($execution->context($operation)['result'])->toBe(['node_restore_required' => true]);
    expect(fn () => $execution->result($operation))->toThrow(ResourceOperationException::class);
    removalProofChild($operation, 'remove-restore', $restoreFails ? 'failed' : 'succeeded');

    $result = $execution->result($operation);

    expect($result)->toMatchArray(['removed' => false, 'error_code' => $restoreFails ? 'node.removal_rollback_failed' : 'route.cleanup_failed']);
    $this->assertModelExists($node);
    $this->assertDatabaseHas('routes', ['id' => $route->id, 'node_id' => $node->id]);
})->with([false, true]);

it('uses settled compensation evidence to project cancellation with no parent run', function (): void {
    [$operation] = removalProofOperation();
    removalProofChild($operation, 'remove-begin');
    $operation->update(['cancel_requested_at' => now()]);
    removalProofChild($operation, 'remove-restore');

    $result = app(NodeLifecycleOperationExecution::class)->context($operation)['result'];
    unset($result['node_restore_required']);
    app(NodeLifecycleOperationExecution::class)->assertCompletion($operation, OperationOutcome::cancelled($result));

    expect($result)->toMatchArray(['node_plan_proven' => true, 'removed' => false, 'error_code' => 'operation.cancelled']);
});

it('refuses Node deletion until its exact exporter retirement child succeeds', function (string $status): void {
    [$operation, $node, $route] = removalProofOperation();
    $metrics = FleetFixtures::node('remove-proof-metrics');
    $intent = ['key' => 'remove-metrics', 'kind' => 'metrics.reconcile', 'node_id' => $metrics->id, 'input' => []];
    $operation->update([
        'input' => [...$operation->input, '_node_plan' => [...$operation->input['_node_plan'], ['type' => 'child', ...$intent, 'optional' => false]]],
        'state' => [...$operation->state, 'child_intents' => [...$operation->state['child_intents'], $intent]],
    ]);
    removalProofChild($operation, 'remove-begin');
    removalProofChild($operation, 'remove-residue');
    removalProofChild($operation, 'remove-metrics', $status);
    $node->update(['status' => 'removing']);

    expect(fn () => DB::transaction(fn () => app(NodeRemovalOperationCompletion::class)->complete($operation,
        OperationOutcome::succeeded(['node_plan_proven' => true, 'removed' => true]))))->toThrow(ResourceOperationException::class);
    $this->assertModelExists($node);
    $this->assertModelExists($route);
})->with(['queued', 'running', 'failed']);
