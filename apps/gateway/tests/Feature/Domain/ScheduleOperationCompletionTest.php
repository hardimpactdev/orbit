<?php

declare(strict_types=1);

use App\Domain\Operations\OperationOutcome;
use App\Domain\Schedules\ScheduleOperationCompletion;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;

it('keeps a Schedule removing when its own command is still running', function (): void {
    $node = Node::query()->create(['name' => 'dev', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3']);
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $node->id, 'host_node_id' => $node->id, 'name' => 'proof', 'calendar' => 'daily', 'command' => 'true', 'timeout_seconds' => 3600, 'desired_timer_state' => 'enabled', 'status' => 'removing']);
    $operation = Operation::query()->create(['kind' => 'schedule.destroy', 'target_type' => 'node_schedules', 'target_id' => $node->id, 'node_id' => $node->id, 'input' => ['id' => $schedule->id], 'status' => 'running', 'sequence' => 1, 'attempts' => 1]);
    app(ScheduleOperationCompletion::class)->complete($operation, OperationOutcome::failed('schedule.run_active'));
    expect($schedule->refresh()->status->value)->toBe('removing')->and($schedule->error_code)->toBe('schedule.run_active');
});
