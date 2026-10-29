<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Nodes\NodeEnrollmentOperationCompletion;
use App\Domain\Nodes\NodeLifecycleOperationExecution;
use App\Domain\Operations\OperationOutcome;
use App\Models\Operation;
use Illuminate\Support\Facades\Event;
use Tests\Support\Fleet\FleetFixtures;

it('publishes the completed Node with the creation or update event from retained intent', function (bool $existed): void {
    $node = FleetFixtures::node('enrollment-notice');
    $result = ['id' => $node->id, 'name' => $node->name, 'enrolled' => true];
    $operation = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['_node_plan' => [], '_node_result' => $result], 'state' => ['enrollment_intent' => ['existed' => $existed]], 'status' => 'running', 'sequence' => 1]);
    Event::fake([RecordBroadcast::class]);
    app(NodeEnrollmentOperationCompletion::class)->complete($operation, OperationOutcome::succeeded(['node_plan_proven' => true, ...$result]));

    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === ($existed ? RecordEventType::NodeUpdated : RecordEventType::NodeCreated)
        && $event->id === $node->id && $event->data['name'] === $node->name && $event->data['status'] === 'active');
})->with([false, true]);

it('keeps a refused fresh bootstrap failed and unpinned instead of claiming enrollment succeeded', function (): void {
    $node = FleetFixtures::node('enrollment-refused', managed: false);
    $node->update(['status' => 'provisioning', 'architecture' => null]);
    $step = ['type' => 'run', 'key' => 'bootstrap-probe', 'transport' => 'bootstrap-ssh', 'name' => 'node.probe-bootstrap', 'input' => [], 'result' => [], 'optional' => false];
    $operation = Operation::query()->create(['kind' => 'node.add', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['_node_plan' => [$step], '_node_failure_result' => ['id' => $node->id, 'name' => $node->name, 'enrolled' => false]],
        'state' => ['enrollment_intent' => ['existed' => false], 'executor_runs' => ['bootstrap-probe' => [
            'transport' => $step['transport'], 'name' => $step['name'], 'input' => [], 'result' => ['error_code' => 'node.architecture_mismatch']]]], 'status' => 'running', 'sequence' => 1]);
    $result = app(NodeLifecycleOperationExecution::class)->result($operation);
    app(NodeEnrollmentOperationCompletion::class)->complete($operation, OperationOutcome::failed('node.architecture_mismatch', $result));

    expect($node->fresh()->status->value)->toBe('failed');
    expect($node->fresh()->failed_step)->toBe('bootstrap-probe');
    expect($node->fresh()->ssh_host_fingerprint)->toBeNull();
    expect($node->fresh()->architecture)->toBeNull();
    expect($result['enrolled'])->toBeFalse();
});
