<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Schedules\ScheduleControlProjection;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->socket = $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
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
    $put = $this->socket->sent[1];
    $start = $this->socket->sent[2];
    expect($put['type'])->toBe('vault.put')
        ->and($put['payload']['value'])->toBe($this->payload['command'])
        ->and($start['payload']['workflowIdOrName'])->toBe('operation-schedule-run')
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
    expect($this->socket->sent)->toHaveCount(1);
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
        'id' => $runId, 'workflowName' => 'operation-schedule-run', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => 'schedule.run'],
        'startedAt' => '2026-10-31T20:00:00Z', 'completedAt' => '2026-10-31T20:00:01Z',
        'jobs' => [['name' => 'run', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($code === null ? ['ready' => true] : ['error_code' => $code], JSON_THROW_ON_ERROR)]]]]],
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
        ->and($this->socket->sent)->toHaveCount(4)
        ->and(array_last($this->socket->sent)['type'])->toBe('workflow.history.get');
})->with([['succeeded', null], ['failed', 'schedule.run_failed']]);

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
    expect($this->socket->sent)->toHaveCount(3);
});

it('projects the fixed lifecycle result through native history once', function (string $kind, string $status): void {
    $id = $this->postJson('/api/v1/schedules', $this->payload)->assertAccepted()->json('data.id');
    $schedule = Schedule::query()->sole();
    if ($kind === 'schedule.destroy') {
        Operation::query()->findOrFail($id)->update(['status' => OperationStatus::Succeeded]);
        $schedule->update(['status' => 'active']);
        $id = $this->deleteJson('/api/v1/schedules/'.$schedule->id)->assertAccepted()->json('data.id');
    }
    $operation = Operation::query()->findOrFail($id);
    $runId = $operation->state['swamp_run_id'];
    $code = $kind === 'schedule.create' ? 'schedule.install_failed' : 'schedule.remove_failed';
    config(['swamp.read_token' => 'read.fixture', 'swamp.address' => '127.0.0.1', 'swamp.ca_path' => '/fixture/ca.pem']);
    $before = count($this->socket->sent);
    $this->socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => [
        'id' => $runId, 'workflowName' => str_replace('.', '-', 'operation-'.$kind), 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $kind],
        'startedAt' => '2026-10-31T20:00:00Z', 'completedAt' => '2026-10-31T20:00:01Z',
        'jobs' => [['name' => 'schedule', 'steps' => [['name' => 'result', 'outputs' => ['stdout' => json_encode($status === 'succeeded' ? ['ready' => true] : ['error_code' => $code], JSON_THROW_ON_ERROR)]]]]],
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
