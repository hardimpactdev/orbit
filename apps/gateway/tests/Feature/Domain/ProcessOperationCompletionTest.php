<?php

declare(strict_types=1);

use App\Domain\Operations\OperationOutcome;
use App\Domain\Processes\ProcessOperationCompletion;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;

it('requires a real command receipt before projecting a Process effect', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => ['image' => 'example:1', 'command' => ['sleep', '300']], 'restart_policy' => 'never', 'desired_state' => 'stopped', 'status' => 'provisioning']);
    $operation = Operation::query()->create(['kind' => 'process.restart', 'target_type' => 'process', 'target_id' => $process->id, 'node_id' => $node->id, 'input' => ['process_id' => $process->id, 'created' => false], 'status' => 'running', 'sequence' => 1, 'attempts' => 1]);
    expect(fn () => app(ProcessOperationCompletion::class)->complete($operation, OperationOutcome::succeeded([])))
        ->toThrow(ResourceOperationException::class);
    expect($process->refresh()->desired_state->value)->toBe('stopped');
    $operation->update(['state' => ['steps' => ['process-restart' => []], 'executor_runs' => ['process-restart' => ['transport' => 'agent', 'name' => 'process.control', 'result' => []]]]]);
    $operation->steps()->create(['sequence' => 2, 'position' => 1, 'name' => 'process-restart', 'status' => 'succeeded', 'resumable' => true]);
    $state = $operation->state;
    $state['steps']['process-status'] = ['state' => 'active'];
    $state['executor_runs']['process-status'] = ['transport' => 'agent', 'name' => 'process.status', 'result' => ['state' => 'active']];
    $operation->update(['state' => $state]);
    $operation->steps()->create(['sequence' => 3, 'position' => 2, 'name' => 'process-status', 'status' => 'succeeded', 'resumable' => true]);
    app(ProcessOperationCompletion::class)->complete($operation, OperationOutcome::succeeded(['state' => 'active']));
    expect($process->refresh()->desired_state->value)->toBe('running')->and($process->status->value)->toBe('active');
});

it('refuses an unsettled systemd backup even with proven successful command replies', function (): void {
    $node = Node::query()->create(['name' => 'backup-proof', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.3']);
    $stage = ['staged' => true, 'previous' => true, 'state' => null];
    $activation = ['backup_left' => true];
    $operation = Operation::query()->create(['kind' => 'process.create', 'target_type' => 'process', 'target_id' => 12, 'node_id' => $node->id, 'status' => 'running',
        'input' => ['runtime' => 'systemd', 'desired' => 'stopped', 'activation_pending' => false],
        'state' => ['steps' => ['process-stage-unit' => $stage, 'process-activate-unit' => $activation], 'executor_runs' => [
            'process-stage-unit' => ['transport' => 'agent', 'name' => 'process.stage-unit', 'result' => $stage],
            'process-activate-unit' => ['transport' => 'agent', 'name' => 'process.activate-unit', 'result' => $activation],
        ]]]);
    foreach (['process-stage-unit', 'process-activate-unit'] as $index => $name) {
        $operation->steps()->create(['sequence' => $index + 1, 'position' => $index + 1, 'name' => $name, 'status' => 'succeeded', 'resumable' => true]);
    }
    expect(fn () => app(ProcessOperationCompletion::class)->assertEffects($operation))->toThrow(ResourceOperationException::class);
});
