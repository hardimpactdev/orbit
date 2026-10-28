<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'schedule-admission', 'status' => 'active', 'platform' => 'linux',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->payload = ['target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'throwaway', 'calendar' => 'daily', 'command' => 'printf private-schedule-command'];
});

it('records timer intent with a command reference and replays its UUID', function (): void {
    $response = $this->withHeader('Idempotency-Key', 'schedule-proof')->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($response->getContent())->not->toContain('private-schedule-command')
        ->and($operation->input['command'])->toBe(['$secret' => 'schedule.command', 'id' => $this->node->id])
        ->and($operation->input['desired'])->toBe('enabled')
        ->and(Schedule::query()->sole()->status->value)->toBe('provisioning');
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted()->assertJsonPath('data.id', $operation->id);
});

it('refuses changed specifications and disabled Node timers before recording another Operation', function (): void {
    $this->postJson('/api/v1/schedules', [...$this->payload, 'start' => false])->assertUnprocessable();
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $this->postJson('/api/v1/schedules', [...$this->payload, 'command' => 'false'])->assertConflict()->assertJsonPath('error.code', 'schedule.retry_conflict');
    expect(Operation::query()->count())->toBe(1);
});

it('admits a manual run without changing desired timer state', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $schedule = Schedule::query()->sole();
    $schedule->update(['status' => 'active']);
    $this->postJson('/api/v1/schedules/'.$schedule->id.'/run')->assertAccepted()->assertJsonPath('data.kind', 'schedule.run');
    expect($schedule->refresh()->desired_timer_state->value)->toBe('enabled');
});
