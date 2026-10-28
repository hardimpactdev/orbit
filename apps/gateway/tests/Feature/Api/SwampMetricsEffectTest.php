<?php

declare(strict_types=1);

use App\Actions\Metrics\StartMetricsExporterOperationAction;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Queue;

it('records each fixed exporter effect without dispatching a PHP executor', function (string $component, bool $remove): void {
    Queue::fake();
    $node = Node::query()->create(['name' => 'dev', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']);
    $metrics = Node::query()->create(['name' => 'metrics', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2']);
    $operation = app(StartMetricsExporterOperationAction::class)->execute($node, $metrics, $component, $remove);
    expect($operation->kind)->toBe('metrics.'.$component.($remove ? '_remove' : '_converge'))
        ->and($operation->node_id)->toBe($node->id)->and($operation->status)->toBe(OperationStatus::Queued);
    $input = ['worker' => 'workflow-1', 'key' => 'effect', 'transport' => 'agent',
        'name' => $component === 'node_exporter' ? 'metrics.node-exporter' : 'metrics.cadvisor',
        'input' => [...$operation->input, 'action' => ['operation' => $remove ? 'remove' : 'converge']]];
    app(OperationExecutionContract::class)->authorizeRun($operation, $input);
    expect(fn () => app(OperationExecutionContract::class)->authorizeRun($operation, [...$input,
        'input' => [...$input['input'], 'address' => '10.44.0.99']]))->toThrow(ResourceOperationException::class);
    expect(fn () => app(OperationExecutionContract::class)->authorizeRun($operation, [...$input, 'name' => 'process.start']))->toThrow(ResourceOperationException::class);
    Queue::assertNothingPushed();
})->with([['node_exporter', false], ['node_exporter', true], ['cadvisor', false], ['cadvisor', true]]);

it('rejects a forged completion and strips undeclared secret fields', function (): void {
    $operation = new Operation(['kind' => 'metrics.node_exporter_converge', 'node_id' => 1, 'state' => []]);
    $contract = app(OperationExecutionContract::class);
    expect($contract->result($operation, ['changed' => true, 'token' => 'sentinel', 'environment' => ['password' => 'sentinel']]))->toBe(['changed' => true]);
    expect(fn () => $contract->assertStep($operation, 'node-exporter-converge', ['changed' => true]))->toThrow(ResourceOperationException::class);
});
