<?php

declare(strict_types=1);

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->create(['name' => 'process-direct', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'direct-proof', 'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '60']], 'desired_state' => 'stopped', 'status' => 'active', 'restart_policy' => 'never']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->input = ['process_id' => $this->process->id, 'agent_sha256' => str_repeat('a', 64), 'runtime' => 'systemd', 'name' => 'orbit-process-'.$this->process->id.'-direct-proof.service', 'on_demand' => false, 'vite' => null];
    $this->operation = Operation::query()->create(['kind' => 'process.start', 'target_type' => 'process', 'target_id' => $this->process->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => [...$this->input, 'preserve_record' => false, 'environment' => 'private-value'], 'state' => ['process_control_writer' => 'direct', 'swamp_run_id' => $this->runId]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['ready' => true, 'state' => 'active']];
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => ['kind' => 'started', 'workflowName' => 'operation-process-control', 'runId' => $this->runId]]];
    app()->instance(WebSocketClient::class, $this->socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);
});

it('exposes only safe admitted Process control input and bypasses old claims', function (): void {
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', $this->input);
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'process.start', 'worker' => 'old', 'operation_id' => $this->operation->id])->assertOk()->assertJsonPath('data', null);
});

it('projects native controls without keyed receipts and keeps terminal projection idempotent', function (string $verb, string $state, string $desired): void {
    $this->operation->update(['kind' => 'process.'.$verb]);
    $body = [...$this->body, 'result' => ['ready' => true, 'state' => $state, 'private' => 'discard']];
    $this->postJson($this->path.'/result', $body)->assertOk()->assertJsonPath('data.result', ['ready' => true, 'state' => $state]);
    expect($this->process->fresh()->desired_state->value)->toBe($desired);
    expect($this->process->fresh()->status->value)->toBe('active');
    $sequence = $this->operation->fresh()->sequence;
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($this->operation->fresh()->sequence)->toBe($sequence);
    expect($this->operation->fresh()->attempts)->toBe(0);
    expect($this->operation->fresh()->worker)->toBeNull();
})->with([['start', 'active', 'running'], ['restart', 'active', 'running'], ['stop', 'inactive', 'stopped']]);

it('requires a bounded runtime state and a matching run before projecting success', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'result' => ['ready' => true]])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$this->body, 'result' => ['ready' => true, 'state' => 'active PASSWORD=private']])->assertUnprocessable();
    $this->operation->update(['state' => ['process_control_writer' => 'direct']]);
    $this->postJson($this->path.'/result', $this->body)->assertConflict();
    expect($this->process->fresh()->desired_state->value)->toBe('stopped');
});

it('deletes only after native remove success and preserves a record when admitted', function (bool $preserve): void {
    $this->operation->update(['kind' => 'process.destroy', 'input' => [...$this->operation->input, 'preserve_record' => $preserve]]);
    $body = [...$this->body, 'result' => ['ready' => true]];
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($this->process->fresh() !== null)->toBe($preserve);
    $this->postJson($this->path.'/result', $body)->assertOk();
})->with([false, true]);

it('captures the agent digest and starts one native Process run without a target hold', function (): void {
    config(['swamp.direct_writers' => ['process.stop' => 'direct'], 'dependency_detection.services_node_id' => null, 'operation_executor.worker_node_ids' => []]);
    $operation = app(ProcessOperationAdmission::class)->execute($this->process, 'stop');
    expect($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->node));
    expect($operation->state['process_control_writer'])->toBe('direct');
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->runId);
    expect($operation->lock_key)->toBeNull();
    expect($this->socket->sent)->toHaveCount(1);
});

it('keeps Vite traffic and Instance children on their admitted polled path', function (): void {
    config(['swamp.direct_writers' => ['process.start' => 'direct']]);
    expect(app(DirectOperation::class)->writer('process.start', ['vite' => null]))->toBe('direct');
    expect(app(DirectOperation::class)->writer('process.start', ['vite' => ['instance_id' => 8]]))->toBe('polled');
    app(OperationIdempotency::class)->parent = $this->operation;
    expect(app(DirectOperation::class)->writer('process.start', ['vite' => null]))->toBe('polled');
});

it('projects only a typed native refusal without changing desired state', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'failed', 'error_code' => 'process.start_failed', 'result' => ['error_code' => 'process.start_failed', 'output' => 'private']])->assertOk()->assertJsonPath('data.result', ['error_code' => 'process.start_failed']);
    expect($this->process->fresh()->desired_state->value)->toBe('stopped');
    expect($this->process->fresh()->error_code)->toBe('process.start_failed');
});
