<?php

declare(strict_types=1);

use App\Domain\Operations\OperationOutcome;
use App\Domain\Processes\ProcessOperationCompletion;
use App\Domain\Schedules\ScheduleOperationCompletion;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Schedule;

it('requires a real command receipt before projecting a Process effect', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3']);
    $process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $node->id, 'name' => 'proof', 'runtime' => 'docker', 'runtime_config' => ['image' => 'example:1', 'command' => ['sleep', '300']], 'restart_policy' => 'never', 'desired_state' => 'stopped', 'status' => 'provisioning']);
    $operation = Operation::query()->create(['kind' => 'process.start', 'target_type' => 'process', 'target_id' => $process->id, 'node_id' => $node->id, 'input' => ['process_id' => $process->id, 'created' => false], 'status' => 'running', 'sequence' => 1, 'attempts' => 1]);
    expect(fn () => app(ProcessOperationCompletion::class)->complete($operation, OperationOutcome::succeeded([])))
        ->toThrow(ResourceOperationException::class);
    expect($process->refresh()->desired_state->value)->toBe('stopped');
    $operation->update(['state' => ['steps' => ['process-start' => []], 'executor_runs' => ['process-start' => ['transport' => 'agent', 'name' => 'process.control', 'result' => []]]]]);
    $operation->steps()->create(['sequence' => 2, 'position' => 1, 'name' => 'process-start', 'status' => 'succeeded', 'resumable' => true]);
    app(ProcessOperationCompletion::class)->complete($operation, OperationOutcome::succeeded([]));
    expect($process->refresh()->desired_state->value)->toBe('running')->and($process->status->value)->toBe('active');
});

it('keeps a Schedule removing when its own command is still running', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3']);
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $node->id, 'host_node_id' => $node->id, 'name' => 'proof', 'calendar' => 'daily', 'command' => 'true', 'timeout_seconds' => 3600, 'desired_timer_state' => 'enabled', 'status' => 'removing']);
    $operation = Operation::query()->create(['kind' => 'schedule.destroy', 'target_type' => 'node_schedules', 'target_id' => $node->id, 'node_id' => $node->id, 'input' => ['id' => $schedule->id], 'status' => 'running', 'sequence' => 1, 'attempts' => 1]);
    app(ScheduleOperationCompletion::class)->complete($operation, OperationOutcome::failed('schedule.run_active'));
    expect($schedule->refresh()->status->value)->toBe('removing')->and($schedule->error_code)->toBe('schedule.run_active');
});
