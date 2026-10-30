<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Actions\Schedules\RunScheduleAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->create(['name' => 'schedule-direct', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']));
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->schedule = Schedule::query()->create(['id' => '9a067a27-c2ce-45c5-83f5-735e03dc5b2f', 'target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id, 'name' => 'direct-proof', 'calendar' => '*:0/5', 'command' => 'private-command', 'timeout_seconds' => 60, 'desired_timer_state' => 'enabled', 'status' => 'active']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'schedule.run', 'target_type' => 'node_schedules', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => ['id' => $this->schedule->id, 'user' => 'orbit', 'working_directory' => '/home/orbit', 'agent_sha256' => str_repeat('a', 64), 'unrelated' => 'private-value'], 'state' => ['schedule_control_writer' => 'direct', 'swamp_run_id' => $this->runId]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-schedule-control', 'runId' => $this->runId,
    ]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);
});

it('exposes only admitted safe input through the shared endpoint and declares no relay', function (): void {
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', ['id' => $this->schedule->id, 'user' => 'orbit', 'working_directory' => '/home/orbit', 'agent_sha256' => str_repeat('a', 64)]);
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'schedule.run', 'worker' => 'old', 'operation_id' => $this->operation->id])->assertOk()->assertJsonPath('data', null);
});

it('projects safe native results once and refuses conflicting or unproven completion', function (): void {
    $body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['ready' => true, 'password' => 'private-result']];
    $this->postJson($this->path.'/result', [...$body, 'result' => []])->assertUnprocessable();
    $this->postJson($this->path.'/result', $body)->assertOk()->assertJsonPath('data.result', ['ready' => true]);
    $sequence = $this->operation->fresh()->sequence;
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($this->operation->fresh()->sequence)->toBe($sequence);
    expect($this->schedule->fresh()->status->value)->toBe('active');
    expect($this->operation->fresh()->attempts)->toBe(0);
    expect($this->operation->fresh()->worker)->toBeNull();
    $this->postJson($this->path.'/result', [...$body, 'status' => 'failed', 'error_code' => 'schedule.run_failed', 'result' => ['error_code' => 'schedule.run_failed']])->assertConflict();
});

it('refuses worker access before Gateway start binding and never binds from a result', function (): void {
    $this->operation->update(['state' => ['schedule_control_writer' => 'direct']]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $this->postJson($this->path.'/result', ['run_id' => $this->runId, 'status' => 'running', 'started_at' => '2026-10-30T12:00:00.000Z'])->assertConflict();
    expect($this->operation->fresh()->state)->toBe(['schedule_control_writer' => 'direct']);
    expect($this->operation->fresh()->steps()->count())->toBe(0);
});

it('checks shared start-response and first-read ordering without a family token or target hold', function (): void {
    DB::beginTransaction();
    config(['swamp.direct_writers' => ['schedule.run' => 'direct']]);
    $operation = app(StartOperationAction::class)->execute('schedule.run', 'node_schedules', $this->node->id, $this->node->id, ['id' => $this->schedule->id, 'user' => 'orbit', 'working_directory' => '/home/orbit'], 'test', null);
    $path = '/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId;
    $this->getJson($path)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    expect($this->socket->sent)->toBe([]);
    DB::commit();
    $this->getJson($path)->assertOk();
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->endpoint->bearerToken)->toBe('shared.scoped.fixture');
    expect($this->socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-schedule-control', 'inputs' => ['operation_id' => $operation->id, 'kind' => 'schedule.run', 'node_id' => $this->node->id]]);
    expect($operation->fresh()->lock_key)->toBeNull();
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->runId);
});

it('keeps previous work claimable through the shared per-kind switch and rollback', function (): void {
    $start = app(StartOperationAction::class);
    $input = ['id' => $this->schedule->id, 'user' => 'orbit', 'working_directory' => '/home/orbit'];
    config(['swamp.direct_writers' => []]);
    $old = $start->execute('schedule.run', 'node_schedules', $this->node->id, $this->node->id, $input, 'test', null);
    expect($old->state['schedule_control_writer'])->toBe('polled');
    config(['swamp.direct_writers' => ['schedule.run' => 'direct']]);
    expect(app(DirectOperation::class)->writer('schedule.enable'))->toBe('polled');
    $direct = $start->execute('schedule.run', 'node_schedules', $this->node->id, $this->node->id, $input, 'test', null);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'schedule.run', 'worker' => 'old', 'operation_id' => $old->id])->assertOk()->assertJsonPath('data.id', $old->id);
    config(['swamp.direct_writers' => []]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'schedule.run', 'worker' => 'old', 'operation_id' => $direct->id])->assertOk()->assertJsonPath('data', null);
    expect($this->socket->sent)->toHaveCount(1);
});

it('restricts Node workers to their admitted Node and preserves the control-plane peer', function (): void {
    $own = Node::query()->create(['name' => 'schedule-owner', 'public_ssh_host' => '192.0.2.10', 'user' => 'orbit', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.10']);
    $foreign = Node::query()->create(['name' => 'schedule-foreign', 'public_ssh_host' => '192.0.2.11', 'user' => 'orbit', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.11']);
    foreach ([$own, $foreign] as $peer) {
        $peer->accessibleNodes()->attach($this->node);
    }
    config(['operation_executor.worker_node_ids' => [$own->id, $foreign->id]]);
    $this->operation->update(['node_id' => $own->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $own->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
    $this->getJson($this->path.'?run_id=fabd4059-fb6c-49d8-940e-01ae58aa3f38')->assertConflict();
});

it('projects a bounded failure without enabling the timer', function (): void {
    $this->operation->update(['kind' => 'schedule.enable']);
    $this->schedule->update(['desired_timer_state' => 'disabled']);
    $body = ['run_id' => $this->runId, 'status' => 'failed', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'error_code' => 'schedule.activation_failed', 'result' => ['error_code' => 'other.failure']];
    $this->postJson($this->path.'/result', $body)->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$body, 'result' => ['error_code' => 'schedule.activation_failed']])->assertOk();
    expect($this->schedule->fresh()->error_code)->toBe('schedule.activation_failed');
    expect($this->schedule->fresh()->desired_timer_state->value)->toBe('disabled');
});

it('projects a successful enable into desired timer state', function (): void {
    $this->operation->update(['kind' => 'schedule.enable']);
    $this->schedule->update(['desired_timer_state' => 'disabled']);
    $this->postJson($this->path.'/result', ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['ready' => true]])->assertOk();
    expect($this->schedule->fresh()->desired_timer_state->value)->toBe('enabled');
    expect($this->schedule->fresh()->error_code)->toBeNull();
});

it('exposes admitted removal input and deletes the Schedule only on native success', function (): void {
    $this->operation->update(['kind' => 'schedule.destroy', 'input' => ['id' => $this->schedule->id, 'agent_sha256' => str_repeat('a', 64), 'group' => 'orbit', 'cascade' => false, 'command' => 'private-command']]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', ['id' => $this->schedule->id, 'agent_sha256' => str_repeat('a', 64), 'group' => 'orbit', 'cascade' => false]);
    expect($this->schedule->fresh())->not->toBeNull();
    $body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['ready' => true]];
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($this->schedule->fresh())->toBeNull();
    $this->postJson($this->path.'/result', $body)->assertOk();
});

it('captures the delivered agent digest during direct Schedule admission', function (): void {
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    config(['swamp.direct_writers' => ['schedule.run' => 'direct'], 'dependency_detection.services_node_id' => null, 'operation_executor.worker_node_ids' => []]);
    $operation = app(RunScheduleAction::class)->admit($this->schedule);
    expect($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->node));
    expect($operation->state['schedule_control_writer'])->toBe('direct');
    expect($operation->lock_key)->toBeNull();
});

it('keeps Instance child removals polled while standalone destroy can select direct', function (): void {
    config(['swamp.direct_writers' => ['schedule.destroy' => 'direct']]);
    expect(app(DirectOperation::class)->writer('schedule.destroy'))->toBe('direct');
    app(OperationIdempotency::class)->parent = $this->operation;
    expect(app(DirectOperation::class)->writer('schedule.destroy'))->toBe('polled');
});
