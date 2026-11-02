<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Schedules\ScheduleControlProjection;
use App\Domain\Schedules\ScheduleOperationAdmission;
use App\Domain\Schedules\ScheduleOperationCompletion;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;
use Tests\Support\Schedules\NativeScheduleReplies;

beforeEach(function (): void {
    $this->socket = $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => NativeScheduleReplies::for($request)
        ?? [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'schedule-admission', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->payload = ['target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'throwaway', 'calendar' => 'daily', 'command' => 'printf private-schedule-command'];
});

it('creates the Schedule workflow and its trigger, answers with a succeeded task and replays its UUID', function (): void {
    $response = $this->withHeader('Idempotency-Key', 'schedule-proof')->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    $schedule = Schedule::query()->sole();
    expect($response->getContent())->not->toContain('private-schedule-command')
        ->and($operation->status)->toBe(OperationStatus::Succeeded)
        ->and($operation->state)->not->toHaveKey('swamp_run_id')
        ->and($operation->input['command'])->toBe(['$secret' => 'schedule.command', 'id' => $this->node->id])
        ->and($schedule->status->value)->toBe('active')
        ->and($schedule->trigger->value)->toBe('swamp')
        ->and($schedule->swamp_workflow_id)->toBe(NativeScheduleReplies::workflowId('schedule-'.$schedule->id))
        ->and(array_column($this->socket->sent, 'type'))->toBe(['vault.put', 'workflow.create', 'workflow.edit', 'workflow.trigger.set']);
    [$put, $create, $edit, $trigger] = $this->socket->sent;
    $content = json_decode($edit['payload']['content'], true, flags: JSON_THROW_ON_ERROR);
    $call = $content['jobs'][0]['steps'][0]['task'];
    expect($put['payload']['key'])->toBe('schedule-command-'.$schedule->id)
        ->and($create['payload']['name'])->toBe('schedule-'.$schedule->id)
        ->and($content['id'])->toBe(NativeScheduleReplies::workflowId('schedule-'.$schedule->id))
        ->and($call['workflowIdOrName'])->toBe('task-command-run')
        ->and($call['inputs']['execution_secrets']['command'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'schedule-command-'.$schedule->id])
        ->and($call['inputs']['intent']['schedule']['command_hash'])->toBe(hash('sha256', $this->payload['command']))
        ->and($content['tags']['orbit_node_id'])->toBe((string) $this->node->id)
        ->and($trigger['payload'])->toBe(['workflowName' => 'schedule-'.$schedule->id, 'schedule' => 'daily'])
        ->and(json_encode($create, JSON_THROW_ON_ERROR).$edit['payload']['content'])->not->toContain('private-schedule-command');
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted()->assertJsonPath('data.id', $operation->id);
});

it('marks the Schedule failed and records no task when serve does not confirm', function (): void {
    $this->socket->reply = fn (array $request): array => $request['type'] === 'workflow.trigger.set' ? [] : NativeScheduleReplies::for($request);
    $this->postJson('/api/v1/schedules', $this->payload)->assertStatus(503)->assertJsonPath('error.code', 'swamp.schedule_publish_unavailable');
    expect(Operation::query()->count())->toBe(0)
        ->and(Schedule::query()->sole()->status->value)->toBe('failed')
        ->and(Schedule::query()->sole()->error_code)->toBe('swamp.schedule_publish_unavailable');
    $this->socket->reply = fn (array $request): ?array => NativeScheduleReplies::for($request);
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    expect(Schedule::query()->sole()->status->value)->toBe('active')
        ->and(collect($this->socket->sent)->where('type', 'workflow.create')->count())->toBe(1);
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
    $put = $this->socket->sent[4];
    $start = $this->socket->sent[5];
    expect($put['type'])->toBe('vault.put')
        ->and($put['payload']['value'])->toBe($this->payload['command'])
        ->and($start['payload']['workflowIdOrName'])->toBe('task-command-run')
        ->and($start['payload']['inputs']['intent']['schedule']['command_hash'])->toBe(hash('sha256', $this->payload['command']))
        ->and($start['payload']['inputs']['intent']['ssh_execution']['control_node_id'])->toBe(209)
        ->and($start['payload']['inputs']['intent']['ssh_execution']['target']['host_keys'])->toBe(['ssh-ed25519 fixture-host-key'])
        ->and($start['payload']['inputs']['execution_secrets']['command'])->toBe(['vault' => 'orbit-control-inputs', 'key' => $put['payload']['key']])
        ->and(json_encode($start, JSON_THROW_ON_ERROR))->not->toContain($this->payload['command']);
});

it('admits different Schedules without Gateway target holds', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $this->postJson('/api/v1/schedules', [...$this->payload, 'name' => 'another-timer'])
        ->assertAccepted()->assertJsonPath('data.kind', 'schedule.create');
    expect(Schedule::query()->count())->toBe(2)->and(Operation::query()->count())->toBe(2);
    expect(Operation::query()->whereNotNull('lock_key')->count())->toBe(0);
});

it('refuses an unpinned Schedule target before copying its command or starting a run', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded]);
    $schedule = Schedule::query()->sole();
    $schedule->update(['status' => 'active']);
    $this->node->update(['ssh_host_key' => null]);
    $this->postJson('/api/v1/schedules/'.$schedule->id.'/run')->assertStatus(503)->assertJsonPath('error.code', 'swamp.ssh_target_unavailable');
    expect($this->socket->sent)->toHaveCount(4);
});

it('projects the fixed native run result once and preserves disabled timer intent', function (string $status, ?string $code): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded]);
    $schedule = Schedule::query()->sole();
    $schedule->update(['status' => 'active', 'desired_timer_state' => 'disabled']);
    $id = $this->postJson('/api/v1/schedules/'.$schedule->id.'/run')->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $runId = $operation->state['swamp_run_id'];
    config(['swamp.read_token' => 'read.fixture', 'swamp.address' => '127.0.0.1', 'swamp.ca_path' => '/fixture/ca.pem']);
    $this->socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $runId, 'workflowName' => 'task-command-run', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'schedule.run'],
        'startedAt' => '2026-10-31T20:00:00Z', 'completedAt' => '2026-10-31T20:00:01Z',
        'jobs' => [['name' => 'run', 'steps' => [['name' => 'Report the result', 'outputs' => ['stdout' => json_encode($code === null ? ['ready' => true] : ['error_code' => $code], JSON_THROW_ON_ERROR)]]]]],
    ]]]];
    $step = app(SwampCommandStep::class);
    $selector = app(DirectOperation::class)->definition('schedule.run')['terminal_result'];
    $projected = $step->projectTerminal($operation, ['id' => $runId, 'status' => $status], $selector);
    $sequence = $projected->sequence;
    $step->projectTerminal($operation, ['id' => $runId, 'status' => $status], $selector);
    expect($projected->status->value)->toBe($status)
        ->and($operation->fresh()->sequence)->toBe($sequence)
        ->and($schedule->fresh()->desired_timer_state->value)->toBe('disabled')
        ->and($schedule->fresh()->last_run_status?->value)->toBe($status === 'succeeded' ? 'success' : 'error')
        ->and($this->socket->sent)->toHaveCount(7)
        ->and(array_last($this->socket->sent)['type'])->toBe('workflow.history.get');
})->with([['succeeded', null], ['failed', 'schedule.run_failed'], ['interrupted', 'operation.native_failed']]);

it('refuses a command changed after admission before making another execution copy', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded]);
    $schedule = Schedule::query()->sole();
    $schedule->update(['status' => 'active']);
    $id = $this->postJson('/api/v1/schedules/'.$schedule->id.'/run')->assertAccepted()->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    $schedule->update(['command' => 'changed private command']);
    expect(fn () => app(ScheduleControlProjection::class)->input($operation))
        ->toThrow(ResourceOperationException::class, 'changed after admission');
    expect($this->socket->sent)->toHaveCount(6);
});

it('projects a Node timer Schedule lifecycle result through native history once', function (string $kind, string $status): void {
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'laravel-scheduler', 'calendar' => '*-*-* *:*:00', 'command' => 'php artisan schedule:run', 'timeout_seconds' => 300,
        'desired_timer_state' => 'enabled', 'status' => 'provisioning', 'trigger' => 'node']);
    $id = app(ScheduleOperationAdmission::class)->execute($schedule, 'create')->id;
    if ($kind === 'schedule.destroy') {
        Operation::query()->findOrFail($id)->update(['status' => OperationStatus::Succeeded]);
        $schedule->update(['status' => 'active']);
        $id = $this->deleteJson('/api/v1/schedules/'.$schedule->id)->assertAccepted()->json('data.id');
    }
    $operation = Operation::query()->findOrFail($id);
    $runId = $operation->state['swamp_run_id'];
    $code = 'operation.native_failed';
    config(['swamp.read_token' => 'read.fixture', 'swamp.address' => '127.0.0.1', 'swamp.ca_path' => '/fixture/ca.pem']);
    $before = count($this->socket->sent);
    $this->socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $runId, 'workflowName' => str_replace('.', '-', 'operation-'.$kind), 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $kind],
        'startedAt' => '2026-10-31T20:00:00Z', 'completedAt' => '2026-10-31T20:00:01Z',
        'jobs' => [['name' => 'schedule', 'steps' => [['name' => 'result', 'status' => $status === 'succeeded' ? 'succeeded' : 'skipped', 'outputs' => $status === 'succeeded' ? ['stdout' => '{"ready":true}'] : []]]]],
    ]]]];
    $step = app(SwampCommandStep::class);
    $selector = app(DirectOperation::class)->definition($kind)['terminal_result'];
    $projected = $step->projectTerminal($operation, ['id' => $runId, 'status' => $status], $selector);
    $step->projectTerminal($operation, ['id' => $runId, 'status' => $status], $selector);
    expect($projected->status->value)->toBe($status)
        ->and(count($this->socket->sent))->toBe($before + 1);
    if ($kind === 'schedule.destroy' && $status === 'succeeded') {
        expect($schedule->fresh())->toBeNull();
    } else {
        expect($schedule->fresh()->status->value)->toBe($status === 'succeeded' ? 'active' : ($kind === 'schedule.create' ? 'failed' : 'removing'));
        expect($schedule->fresh()->error_code)->toBe($status === 'succeeded' ? null : $code);
    }
})->with([['schedule.create', 'succeeded'], ['schedule.create', 'failed'], ['schedule.destroy', 'succeeded'], ['schedule.destroy', 'failed']]);

it('removes the trigger, then the workflow, and deletes the record with a succeeded task', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $schedule = Schedule::query()->sole();
    $before = count($this->socket->sent);
    $id = $this->deleteJson('/api/v1/schedules/'.$schedule->id)->assertAccepted()->assertJsonPath('data.kind', 'schedule.destroy')->json('data.id');
    $sent = array_slice($this->socket->sent, $before);
    expect(array_column($sent, 'type'))->toBe(['workflow.trigger.remove', 'workflow.delete'])
        ->and($sent[0]['payload'])->toBe(['workflowName' => 'schedule-'.$schedule->id])
        ->and($sent[1]['payload'])->toBe(['workflowIdOrName' => $schedule->swamp_workflow_id])
        ->and(Operation::query()->findOrFail($id)->status)->toBe(OperationStatus::Succeeded)
        ->and(Schedule::query()->count())->toBe(0);
});

it('keeps a Schedule removing when serve does not confirm the removal, and a rerun finishes it', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $schedule = Schedule::query()->sole();
    $this->socket->reply = fn (array $request): ?array => $request['type'] === 'workflow.delete' ? [] : NativeScheduleReplies::for($request);
    $this->deleteJson('/api/v1/schedules/'.$schedule->id)->assertStatus(503)->assertJsonPath('error.code', 'swamp.schedule_publish_unavailable');
    expect($schedule->fresh()->status->value)->toBe('removing')
        ->and(Operation::query()->where('kind', 'schedule.destroy')->count())->toBe(0);
    $this->socket->reply = fn (array $request): ?array => NativeScheduleReplies::for($request);
    $this->deleteJson('/api/v1/schedules/'.$schedule->id)->assertAccepted();
    expect(Schedule::query()->count())->toBe(0);
});

it('sets the trigger again on enable without touching the Node', function (): void {
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $schedule = Schedule::query()->sole();
    $before = count($this->socket->sent);
    $id = $this->postJson('/api/v1/schedules/'.$schedule->id.'/activate')->assertAccepted()->assertJsonPath('data.kind', 'schedule.enable')->json('data.id');
    expect(array_column(array_slice($this->socket->sent, $before), 'type'))->toBe(['workflow.trigger.set'])
        ->and(Operation::query()->findOrFail($id)->status)->toBe(OperationStatus::Succeeded)
        ->and($schedule->fresh()->desired_timer_state->value)->toBe('enabled');
});

it('moves a Node timer Schedule to its own workflow first and then removes the timer without deleting the record', function (): void {
    $schedule = Schedule::query()->create(['target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id,
        'name' => 'nightly-compact', 'calendar' => '*-*-* 03:17:00', 'command' => 'php compact.php', 'timeout_seconds' => 600,
        'desired_timer_state' => 'enabled', 'status' => 'active', 'trigger' => 'node']);
    $this->artisan('schedules:move-to-swamp', ['schedule' => $schedule->id, 'cron' => '17 3 * * *'])->assertSuccessful();
    $types = array_column($this->socket->sent, 'type');
    $removal = Operation::query()->where('kind', 'schedule.destroy')->sole();
    expect(array_slice($types, 0, 4))->toBe(['vault.put', 'workflow.create', 'workflow.edit', 'workflow.trigger.set'])
        ->and(array_last($this->socket->sent)['payload']['workflowIdOrName'])->toBe('operation-schedule-destroy')
        ->and($schedule->fresh()->trigger->value)->toBe('swamp')
        ->and($schedule->fresh()->calendar)->toBe('17 3 * * *')
        ->and($schedule->fresh()->status->value)->toBe('active');
    app(ScheduleOperationCompletion::class)->complete($removal, OperationOutcome::succeeded(['ready' => true]));
    expect($schedule->fresh())->not->toBeNull();
});

it('creates and runs a Schedule from its Placement without reading the account on the Node', function (): void {
    $accounts = Mockery::mock(ScheduleRuntimeAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ScheduleRuntimeAccountResolver::class, $accounts);
    $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted();
    $schedule = Schedule::query()->sole();
    $this->postJson('/api/v1/schedules/'.$schedule->id.'/run')->assertAccepted();
    $intent = array_last($this->socket->sent)['payload']['inputs']['intent']['schedule'];
    expect($intent)->toEqualCanonicalizing(['id' => $schedule->id, 'command_hash' => hash('sha256', $this->payload['command']), 'user' => 'orbit',
        'login_shell' => true, 'timeout_seconds' => 3600, 'working_directory' => '']);
});

it('answers a calendar serve refuses with schedule.calendar_invalid and keeps the Schedule failed', function (): void {
    $this->socket->reply = fn (array $request): ?array => $request['type'] === 'workflow.trigger.set'
        ? [['type' => 'error', 'id' => $request['id'], 'error' => ['code' => 'workflow_trigger_set_failed', 'message' => 'An internal error occurred']]]
        : NativeScheduleReplies::for($request);
    $this->postJson('/api/v1/schedules', [...$this->payload, 'calendar' => 'TZ=Europe/Amsterdam 0 4 * * *'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'schedule.calendar_invalid');
    expect(Schedule::query()->sole()->status->value)->toBe('failed')->and(Operation::query()->count())->toBe(0);
});
