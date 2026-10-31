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

it('records the native failure without Gateway step evidence or timer-state changes', function (string $verb, string $status): void {
    [$schedule, $operation] = nativeScheduleProjection($verb);
    app(ScheduleOperationCompletion::class)->complete($operation, OperationOutcome::failed('schedule.fixture_refused'));
    expect($schedule->refresh()->status->value)->toBe($status)
        ->and($schedule->desired_timer_state->value)->toBe('disabled')
        ->and($schedule->error_code)->toBe('schedule.fixture_refused')
        ->and($schedule->failed_step)->toBeNull()
        ->and(Operation::query()->count())->toBe(1);
})->with([['create', 'failed'], ['enable', 'active'], ['run', 'active'], ['destroy', 'removing']]);

it('keeps a created timer disabled and enables only an explicit successful native enable', function (string $verb, string $desired): void {
    [$schedule, $operation] = nativeScheduleProjection($verb);
    app(ScheduleOperationCompletion::class)->complete($operation, OperationOutcome::succeeded(['ready' => true]));
    expect($schedule->refresh()->status->value)->toBe('active')
        ->and($schedule->desired_timer_state->value)->toBe($desired)
        ->and($schedule->error_code)->toBeNull()
        ->and($schedule->failed_step)->toBeNull();
})->with([['create', 'disabled'], ['enable', 'enabled'], ['run', 'disabled']]);

/** @return array{Schedule,Operation} */
function nativeScheduleProjection(string $verb): array
{
    $node = Node::query()->create(['name' => 'native-schedule', 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9']);
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $node->id, 'host_node_id' => $node->id,
        'name' => 'native', 'calendar' => 'daily', 'command' => '/usr/bin/true', 'timeout_seconds' => 60,
        'desired_timer_state' => 'disabled', 'status' => 'active', 'error_code' => 'schedule.old_failure', 'failed_step' => 'retired-step']);
    $operation = Operation::query()->create(['kind' => 'schedule.'.$verb, 'target_type' => 'node_schedules', 'target_id' => $node->id,
        'node_id' => $node->id, 'input' => ['id' => $schedule->id], 'status' => 'running']);

    return [$schedule, $operation];
}
