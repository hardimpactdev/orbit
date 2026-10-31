<?php

declare(strict_types=1);

use App\Actions\Operations\StartOperationAction;
use App\Actions\Schedules\RunScheduleAction;
use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Schedules\ScheduleRuntimeAccountResolver;
use App\Domain\Schedules\ScheduleRuntimeManager;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->create(['name' => 'schedule-direct', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-host-key', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']));
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->schedule = Schedule::query()->create(['id' => '9a067a27-c2ce-45c5-83f5-735e03dc5b2f', 'target_type' => Node::class, 'target_id' => $this->node->id, 'host_node_id' => $this->node->id, 'name' => 'direct-proof', 'calendar' => '*:0/5', 'command' => 'private-command', 'timeout_seconds' => 60, 'desired_timer_state' => 'enabled', 'status' => 'active']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'schedule.enable', 'target_type' => 'node_schedules', 'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => ['id' => $this->schedule->id, 'user' => 'orbit', 'working_directory' => '/home/orbit', 'unrelated' => 'private-value'], 'state' => ['swamp_run_id' => $this->runId]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => [
            'kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => $this->runId,
        ]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);
});

it('exposes only admitted safe input through the shared endpoint and declares no relay', function (): void {
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', ['id' => $this->schedule->id, 'user' => 'orbit', 'working_directory' => '/home/orbit']);
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
});

it('projects native status once without validating native readiness again', function (): void {
    $body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['password' => 'private-result']];
    $this->postJson($this->path.'/result', $body)->assertOk()->assertJsonPath('data.result', ['ready' => true]);
    $sequence = $this->operation->fresh()->sequence;
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($this->operation->fresh()->sequence)->toBe($sequence);
    expect($this->schedule->fresh()->status->value)->toBe('active');
    $this->postJson($this->path.'/result', [...$body, 'status' => 'failed', 'error_code' => 'schedule.run_failed', 'result' => ['error_code' => 'schedule.run_failed']])->assertConflict();
});

it('refuses worker access before Gateway start binding and never binds from a result', function (): void {
    $this->operation->update(['state' => []]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    $this->postJson($this->path.'/result', ['run_id' => $this->runId, 'status' => 'running', 'started_at' => '2026-10-30T12:00:00.000Z'])->assertConflict();
    expect($this->operation->fresh()->state)->toBe([]);
});

it('checks shared start-response and first-read ordering without a family token or target hold', function (): void {
    DB::beginTransaction();
    $operation = app(StartOperationAction::class)->execute('schedule.enable', 'node_schedules', $this->node->id, $this->node->id, ['id' => $this->schedule->id, 'user' => 'orbit', 'working_directory' => '/home/orbit'], 'test', null);
    $path = '/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId;
    $this->getJson($path)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    expect($this->socket->sent)->toBe([]);
    DB::commit();
    $this->getJson($path)->assertOk();
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->endpoint->bearerToken)->toBe('shared.scoped.fixture');
    expect($this->socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-schedule-control', 'inputs' => ['operation_id' => $operation->id, 'kind' => 'schedule.enable', 'node_id' => $this->node->id], 'runtimeTags' => ['orbit_start_key' => 'operation:'.$operation->id]]);
    expect($operation->fresh()->lock_key)->toBeNull();
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->runId);
});

it('restricts Node workers without the legacy allowlist and preserves the control-plane peer', function (): void {
    $own = Node::query()->create(['name' => 'schedule-owner', 'public_ssh_host' => '192.0.2.10', 'user' => 'orbit', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.10']);
    $foreign = Node::query()->create(['name' => 'schedule-foreign', 'public_ssh_host' => '192.0.2.11', 'user' => 'orbit', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.11']);
    foreach ([$own, $foreign] as $peer) {
        $peer->accessibleNodes()->attach($this->node);
    }
    config(['operation_executor.worker_node_ids' => []]);
    $this->operation->update(['node_id' => $own->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $foreign->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $own->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $own->wireguard_ip]);
    $this->getJson($this->path.'?run_id=fabd4059-fb6c-49d8-940e-01ae58aa3f38')->assertConflict();
});

it('projects a bounded failure without enabling the timer', function (): void {
    $this->operation->update(['kind' => 'schedule.run', 'input' => [...$this->operation->input, 'command_hash' => hash('sha256', $this->schedule->command)]]);
    $this->schedule->update(['desired_timer_state' => 'disabled']);
    $body = ['run_id' => $this->runId, 'status' => 'failed', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'error_code' => 'schedule.run_failed', 'result' => ['error_code' => 'other.failure']];
    $this->postJson($this->path.'/result', $body)->assertOk()->assertJsonPath('data.result', ['error_code' => 'schedule.run_failed']);
    expect($this->schedule->fresh()->error_code)->toBe('schedule.run_failed');
    expect($this->schedule->fresh()->desired_timer_state->value)->toBe('disabled');
});

it('projects a successful enable into desired timer state', function (): void {
    config(['dependency_detection.services_node_id' => null, 'operation_executor.worker_node_ids' => []]);
    $this->operation->update(['kind' => 'schedule.enable']);
    $this->schedule->update(['desired_timer_state' => 'disabled']);
    Event::fake([RecordBroadcast::class]);
    $this->postJson($this->path.'/result', ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['ready' => true]])->assertOk();
    expect($this->schedule->fresh()->desired_timer_state->value)->toBe('enabled');
    expect($this->schedule->fresh()->error_code)->toBeNull();
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ScheduleUpdated && $event->id === $this->schedule->id);
});

it('admits native installation without a Gateway lock and restricts private command reads to the bound run', function (): void {
    $lock = Mockery::mock(ProcessAdmissionLock::class);
    $lock->shouldNotReceive('run');
    app()->instance(ProcessAdmissionLock::class, $lock);
    app()->instance(ScheduleRuntimeManager::class, Mockery::mock(ScheduleRuntimeManager::class));
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    $command = '/usr/bin/true # private-command-sentinel';
    $response = $this->postJson('/api/v1/schedules', ['target_type' => 'node', 'target_id' => $this->node->id,
        'name' => 'install-proof', 'calendar' => '2099-01-01 00:00:00', 'command' => $command, 'timeout_seconds' => 5])
        ->assertAccepted()->assertJsonPath('data.kind', 'schedule.create');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($this->socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-schedule-create');
    expect($operation->lock_key)->toBeNull();
    expect($operation->input['command_hash'])->toBe(hash('sha256', $command));
    expect($response->getContent())->not->toContain($command);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $this->getJson($path.'?run_id='.$this->runId)->assertOk()->assertJsonMissingPath('data.input.command');
    $private = $this->getJson($path.'/private-material?run_id='.$this->runId)->assertOk()->assertJsonPath('data.command', $command);
    expect($private->headers->get('Cache-Control'))->toContain('no-store');
    $this->getJson($path.'/private-material?run_id=93472cba-f750-45dd-bd6f-8d665f57f0d6')->assertConflict();
    $schedule = Schedule::query()->findOrFail($operation->input['id']);
    $schedule->update(['command' => '/usr/bin/false']);
    $this->getJson($path.'/private-material?run_id='.$this->runId)->assertUnprocessable();
    expect($schedule->fresh()->status->value)->toBe('provisioning');
});

it('projects installation failure and retries the same Schedule UUID before broadcasting native success', function (): void {
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    $payload = ['target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'retry-install', 'calendar' => '2099-01-01 00:00:00', 'command' => '/usr/bin/true'];
    $id = $this->postJson('/api/v1/schedules', $payload)->assertAccepted()->json('data.id');
    $first = Operation::query()->findOrFail($id);
    $uuid = $first->input['id'];
    $body = ['run_id' => $this->runId, 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z'];
    $this->postJson('/api/v1/direct-operations/'.$id.'/result', [...$body, 'status' => 'failed', 'error_code' => 'schedule.install_failed', 'result' => ['error_code' => 'schedule.install_failed']])->assertOk();
    expect(Schedule::query()->findOrFail($uuid)->status->value)->toBe('failed');
    $retryId = $this->postJson('/api/v1/schedules', $payload)->assertAccepted()->json('data.id');
    $retry = Operation::query()->findOrFail($retryId);
    expect($retry->input['id'])->toBe($uuid);
    Event::fake([RecordBroadcast::class]);
    $this->postJson('/api/v1/direct-operations/'.$retryId.'/result', [...$body, 'status' => 'succeeded', 'result' => ['ready' => true]])->assertOk();
    expect(Schedule::query()->findOrFail($uuid)->status->value)->toBe('active');
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ScheduleUpdated && $event->id === $uuid);
    $this->getJson('/api/v1/direct-operations/'.$retryId.'/private-material?run_id='.$this->runId)->assertConflict();
});

it('starts native enable on an executor Node without invoking the PHP runtime', function (): void {
    $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Enable proof', 'slug' => 'enable-proof', 'repository_url' => 'https://example.test/enable-proof.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id, 'name' => 'main',
        'environment' => 'development', 'checkout_path' => '/home/orbit/apps/enable-proof', 'status' => 'active', 'provisioning_step' => 'active']);
    $this->schedule->update(['target_type' => Instance::MorphAlias, 'target_id' => $instance->id, 'desired_timer_state' => 'disabled']);
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    app()->instance(ScheduleRuntimeManager::class, Mockery::mock(ScheduleRuntimeManager::class));
    $id = $this->postJson('/api/v1/schedules/'.$this->schedule->id.'/activate')->assertAccepted()->assertJsonPath('data.kind', 'schedule.enable')->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input)->not->toHaveKey('agent_sha256');
    expect($operation->state['swamp_run_id'])->toBe($this->runId);
    expect($operation->lock_key)->toBeNull();
    expect($this->schedule->fresh()->desired_timer_state->value)->toBe('disabled');
    expect($this->socket->sent)->toHaveCount(1);
    config(['dependency_detection.services_node_id' => null, 'operation_executor.worker_node_ids' => []]);
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.kind', 'schedule.enable');
});

it('exposes admitted removal input and deletes the Schedule only on native success', function (): void {
    $this->operation->update(['kind' => 'schedule.destroy', 'input' => ['id' => $this->schedule->id, 'group' => 'orbit', 'cascade' => false, 'command' => 'private-command']]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', ['id' => $this->schedule->id, 'group' => 'orbit', 'cascade' => false]);
    expect($this->schedule->fresh())->not->toBeNull();
    Event::fake([RecordBroadcast::class]);
    $body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['ready' => true]];
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($this->schedule->fresh())->toBeNull();
    $this->postJson($this->path.'/result', $body)->assertOk();
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ScheduleDeleted && $event->id === $this->schedule->id);
});

it('admits destroy on a worker Node without invoking the PHP runtime', function (): void {
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    app()->instance(ScheduleRuntimeManager::class, Mockery::mock(ScheduleRuntimeManager::class));
    $id = $this->deleteJson('/api/v1/schedules/'.$this->schedule->id)->assertAccepted()->assertJsonPath('data.kind', 'schedule.destroy')->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input)->not->toHaveKey('agent_sha256');
    expect($operation->state['swamp_run_id'])->toBe($this->runId);
    expect($operation->lock_key)->toBeNull();
    expect($this->schedule->fresh()->status->value)->toBe('removing');
    expect($this->socket->sent)->toHaveCount(1);
});

it('keeps a refused native removal recorded for a later retry', function (): void {
    $this->operation->update(['kind' => 'schedule.destroy']);
    $this->postJson($this->path.'/result', ['run_id' => $this->runId, 'status' => 'failed',
        'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z',
        'error_code' => 'schedule.run_active', 'result' => ['error_code' => 'schedule.run_active']])->assertOk();
    expect($this->schedule->fresh()->status->value)->toBe('removing');
    expect($this->schedule->fresh()->error_code)->toBe('schedule.run_active');
});

it('does not capture an agent digest during direct Schedule admission', function (): void {
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    config(['dependency_detection.services_node_id' => null, 'operation_executor.worker_node_ids' => []]);
    $operation = app(RunScheduleAction::class)->admit($this->schedule);
    expect($operation->input)->not->toHaveKey('agent_sha256');
    expect($operation->lock_key)->toBeNull();
});

it('admits manual runs directly on a worker Node without PHP runtime execution', function (): void {
    config(['operation_executor.worker_node_ids' => [$this->node->id]]);
    app()->instance(ScheduleRuntimeAccountResolver::class, new FakeScheduleRuntimeAccountResolver);
    app()->instance(ScheduleRuntimeManager::class, Mockery::mock(ScheduleRuntimeManager::class));
    $id = $this->postJson('/api/v1/schedules/'.$this->schedule->id.'/run')->assertAccepted()->assertJsonPath('data.kind', 'schedule.run')->json('data.id');
    $admitted = Operation::query()->findOrFail($id);
    expect($admitted->state['swamp_run_id'])->toBe($this->runId);
    expect($admitted->lock_key)->toBeNull();
    expect($this->socket->sent)->toHaveCount(2);
});
