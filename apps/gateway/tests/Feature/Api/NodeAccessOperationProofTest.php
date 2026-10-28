<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeAccessOperationExecution;
use App\Domain\Nodes\RemoveNodeAccessEdgeCommand;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\NodeAccess;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

it('deletes only the recorded edge and safely repeats without restoring it', function (): void {
    $gateway = FleetFixtures::node('access-gateway');
    $consumer = FleetFixtures::node('access-consumer');
    $other = FleetFixtures::node('access-other');
    NodeAccess::query()->create(['consumer_node_id' => $consumer->id, 'serving_node_id' => $gateway->id]);
    NodeAccess::query()->create(['consumer_node_id' => $other->id, 'serving_node_id' => $gateway->id]);
    $operation = (new Operation)->forceFill(['kind' => 'node.access_remove', 'target_type' => 'node',
        'target_id' => $gateway->id, 'node_id' => $gateway->id, 'input' => ['consumer_node_id' => $consumer->id]]);
    $command = new RemoveNodeAccessEdgeCommand;
    $execution = new NodeAccessOperationExecution;
    $run = ['key' => 'access-remove', 'name' => 'node.remove-access-edge'];
    $result = $command->run($operation, []);
    expect(NodeAccess::query()->count())->toBe(2);
    $execution->received($operation, $run, $result);
    $execution->received($operation, $run, $result);
    expect($result)->toBe(['access_removed' => true])
        ->and($command->run($operation, []))->toBe(['access_removed' => true])
        ->and(NodeAccess::query()->pluck('consumer_node_id')->all())->toBe([$other->id]);
});

it('refuses caller-selected edges and mismatched Operation targets', function (string $variant): void {
    $operation = (new Operation)->forceFill(['kind' => 'node.access_remove', 'target_type' => 'node',
        'target_id' => 2, 'node_id' => 2, 'input' => ['consumer_node_id' => 3]]);
    if ($variant === 'target') {
        $operation->node_id = 4;
    }
    if ($variant === 'kind') {
        $operation->kind = 'node.settings';
    }
    expect(fn () => new RemoveNodeAccessEdgeCommand()->run($operation, $variant === 'input' ? ['consumer_node_id' => 4] : []))
        ->toThrow(ResourceOperationException::class);
})->with(['target', 'kind', 'input']);

it('requires transport-proven deletion before reload or successful completion', function (string $variant): void {
    $receipt = ['transport' => 'gateway', 'name' => 'node.remove-access-edge', 'result' => ['access_removed' => true]];
    $operation = (new Operation)->forceFill(['kind' => 'node.access_remove', 'state' => match ($variant) {
        'forged' => ['steps' => ['access-remove' => ['access_removed' => true]]],
        'failed' => ['executor_runs' => ['access-remove' => [...$receipt, 'result' => ['error_code' => 'operation.cancelled']]]],
        'transport' => ['executor_runs' => ['access-remove' => [...$receipt, 'transport' => 'agent']]],
        default => ['executor_runs' => ['access-remove' => $receipt]],
    }]);
    $execution = new NodeAccessOperationExecution;
    if ($variant === 'proven') {
        $execution->beforeRun($operation, ['name' => 'caddy.reload']);
        $execution->assertCompletion($operation, OperationOutcome::succeeded());
        expect(fn () => $execution->assertCompletion($operation, OperationOutcome::cancelled()))->toThrow(ResourceOperationException::class);
    } else {
        expect(fn () => $execution->beforeRun($operation, ['name' => 'caddy.reload']))->toThrow(ResourceOperationException::class);
        expect(fn () => $execution->assertCompletion($operation, OperationOutcome::succeeded()))->toThrow(ResourceOperationException::class);
        $execution->assertCompletion($operation, OperationOutcome::cancelled());
    }
})->with(['forged', 'failed', 'transport', 'proven']);
