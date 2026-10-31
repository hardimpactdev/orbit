<?php

declare(strict_types=1);

use App\Domain\Broadcasting\RecordBroadcast;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessRuntimeLease;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Caddy\Build\CaddyListenerRule;
use App\Infrastructure\Caddy\Build\CaddySite;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddySiteSource;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use Illuminate\Support\Facades\Event;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->node = $this->markAsGateway(Node::query()->create(['name' => 'process-direct', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.9', 'public_ssh_host' => '192.0.2.9']));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->process = Process::query()->create(['owner_type' => Node::class, 'owner_id' => $this->node->id, 'name' => 'direct-proof', 'runtime' => 'systemd', 'runtime_config' => ['command' => ['/usr/bin/sleep', '60']], 'desired_state' => 'stopped', 'status' => 'active', 'restart_policy' => 'never']);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->input = ['process_id' => $this->process->id, 'agent_sha256' => str_repeat('a', 64), 'runtime' => 'systemd', 'name' => 'orbit-process-'.$this->process->id.'-direct-proof.service', 'on_demand' => false, 'vite' => null];
    $this->operation = Operation::query()->create(['kind' => 'process.stop', 'target_type' => 'process', 'target_id' => $this->process->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1, 'input' => [...$this->input, 'preserve_record' => false, 'environment' => 'private-value'], 'state' => ['process_control_writer' => 'direct', 'swamp_run_id' => $this->runId]]);
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
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'process.stop', 'worker' => 'old', 'operation_id' => $this->operation->id])->assertUnprocessable();
});

it('projects native controls without keyed receipts and keeps terminal projection idempotent', function (string $verb, string $state, string $desired): void {
    $this->operation->update(['kind' => 'process.'.$verb]);
    Event::fake([RecordBroadcast::class]);
    $body = [...$this->body, 'result' => ['ready' => true, 'state' => $state, 'private' => 'discard']];
    $this->postJson($this->path.'/result', $body)->assertOk()->assertJsonPath('data.result', ['ready' => true, 'state' => $state]);
    expect($this->process->fresh()->desired_state->value)->toBe($desired);
    expect($this->process->fresh()->status->value)->toBe('active');
    $sequence = $this->operation->fresh()->sequence;
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($this->operation->fresh()->sequence)->toBe($sequence);
    expect($this->operation->fresh()->attempts)->toBe(0);
    expect($this->operation->fresh()->worker)->toBeNull();
    Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProcessStatus
        && $event->id === $this->process->id && $event->data['runtime_status'] === $state);
})->with([['stop', 'inactive', 'stopped']]);

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
    Event::fake([RecordBroadcast::class]);
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($this->process->fresh() !== null)->toBe($preserve);
    $this->postJson($this->path.'/result', $body)->assertOk();
    if (! $preserve) {
        Event::assertDispatched(RecordBroadcast::class, fn (RecordBroadcast $event): bool => $event->type === RecordEventType::ProcessDeleted && $event->id === $this->process->id);
    }
})->with([false, true]);

it('starts native destroy on an executor Node without a runtime lease or target hold', function (): void {
    $lease = Mockery::mock(ProcessRuntimeLease::class);
    $lease->shouldNotReceive('run');
    app()->instance(ProcessRuntimeLease::class, $lease);
    $id = $this->deleteJson('/api/v1/processes/'.$this->process->id)
        ->assertAccepted()->assertJsonPath('data.kind', 'process.destroy')->json('data.id');
    $operation = Operation::query()->findOrFail($id);
    expect($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->node));
    expect($operation->state['process_control_writer'])->toBe('direct');
    expect($operation->state['swamp_run_id'])->toBe($this->runId);
    expect($operation->lock_key)->toBeNull();
    expect($this->process->fresh()->status->value)->toBe('removing');
    expect($this->socket->sent)->toHaveCount(1);
});

it('keeps a refused native removal record for an explicit retry', function (): void {
    $this->operation->update(['kind' => 'process.destroy']);
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'failed', 'error_code' => 'process.name_collision',
        'result' => ['error_code' => 'process.name_collision']])->assertOk();
    expect($this->process->fresh()->status->value)->toBe('removing');
    expect($this->process->fresh()->error_code)->toBe('process.name_collision');
});

it('uses the direct default for Vite and existing owning destroy admission', function (): void {
    expect(app(DirectOperation::class)->writer('process.destroy', ['vite' => ['instance_id' => 8]]))->toBe('direct');
    app(OperationIdempotency::class)->parent = $this->operation;
    expect(app(DirectOperation::class)->writer('process.destroy', ['vite' => null]))->toBe('direct');
    config(['swamp.direct_writers' => ['process.destroy' => 'polled']]);
    expect(fn () => app(DirectOperation::class)->writer('process.destroy', ['vite' => null]))->toThrow(ResourceOperationException::class);
});

it('starts a native stop on an executor Node without a Gateway runtime lease or target hold', function (): void {
    $lease = Mockery::mock(ProcessRuntimeLease::class);
    $lease->shouldNotReceive('run');
    app()->instance(ProcessRuntimeLease::class, $lease);
    $this->process->update(['desired_state' => 'running']);
    $response = $this->postJson('/api/v1/processes/'.$this->process->id.'/stop')->assertAccepted()->assertJsonPath('data.kind', 'process.stop');
    $operation = Operation::query()->findOrFail($response->json('data.id'));
    expect($operation->input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->node));
    expect($operation->state['process_control_writer'])->toBe('direct');
    expect($operation->fresh()->state['swamp_run_id'])->toBe($this->runId);
    expect($operation->lock_key)->toBeNull();
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->process->fresh()->desired_state->value)->toBe('running');
});

it('admits Vite stop and destroy without traffic preparation', function (string $verb): void {
    $this->node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Vite stop', 'slug' => 'vite-stop', 'repository_url' => 'git@example.test:vite.git']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $this->node->id,
        'name' => 'main', 'environment' => 'development', 'checkout_path' => '/home/orbit/apps/vite-stop',
        'source_is_laravel' => false, 'status' => 'active', 'provisioning_step' => 'active']);
    $this->process->update(['owner_type' => $instance->getMorphClass(), 'owner_id' => $instance->id,
        'runtime_config' => ['preset' => 'vp-dev'], 'desired_state' => 'running']);
    $operation = app(ProcessOperationAdmission::class)->execute($this->process, $verb);
    expect($operation->state['process_control_writer'])->toBe('direct');
    $vite = $verb === 'stop' ? null : ['path' => '/etc/orbit/vite/app-instance-'.$instance->id.'.env', 'instance_id' => $instance->id];
    expect($operation->input['vite'])->toBe($vite);
    expect($operation->input)->not->toHaveKey('vite_plan');
    expect($operation->lock_key)->toBeNull();
    $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$this->runId)
        ->assertOk()->assertJsonPath('data.input.vite', $vite)->assertJsonMissingPath('data.input.vite_plan');
})->with(['stop', 'destroy']);

it('uses one direct start writer for Vite and existing owning admissions', function (): void {
    config(['swamp.direct_writers' => []]);
    expect(app(DirectOperation::class)->writer('process.start', ['vite' => null]))->toBe('direct');
    expect(app(DirectOperation::class)->writer('process.restart', ['vite' => null]))->toBeNull();
    expect(app(DirectOperation::class)->writer('process.start', ['vite' => ['instance_id' => 8]]))->toBe('direct');
    app(OperationIdempotency::class)->parent = $this->operation;
    expect(app(DirectOperation::class)->writer('process.start', ['vite' => null]))->toBe('direct');
});

it('projects only a typed native refusal without changing desired state', function (): void {
    $this->postJson($this->path.'/result', [...$this->body, 'status' => 'failed', 'error_code' => 'process.stop_failed', 'result' => ['error_code' => 'process.stop_failed', 'output' => 'private']])->assertOk()->assertJsonPath('data.result', ['error_code' => 'process.stop_failed']);
    expect($this->process->fresh()->desired_state->value)->toBe('stopped');
    expect($this->process->fresh()->error_code)->toBe('process.stop_failed');
});

it('refreshes same-root public Caddy input on every Vite read without saving the render or exposing private fields', function (): void {
    $source = Mockery::mock(NodeCaddySiteSource::class);
    $source->shouldReceive('order')->andReturn(1);
    $source->shouldReceive('sites')->andReturnUsing(fn (Node $node): array => [new CaddySite(
        'test', 'current', CaddyListenerRule::WireGuard, ['preview.test'], 443, "preview.test { respond {$node->name} }",
    )]);
    app()->instance(NodeCaddyfileRenderer::class, new NodeCaddyfileRenderer([$source]));
    $this->operation->update(['kind' => 'process.start', 'input' => [...$this->operation->input, 'vite_plan' => ['admitted' => 'public-plan'], 'command' => 'private-command']]);
    $original = $this->operation->input;
    $before = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertDontSee('private-command')->json('data.input');
    $this->node->update(['name' => 'fresh-vite-port-owner']);
    $after = $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertDontSee('private-command')->json('data.input');
    expect($after['caddy_publication']['node_id'])->toBe($this->operation->node_id)
        ->and($after['caddy_publication']['caddy']['content'])->toContain('fresh-vite-port-owner')
        ->and($after['caddy_publication']['caddy']['version'])->not->toBe($before['caddy_publication']['caddy']['version'])
        ->and($after['vite_plan'])->toBe(['admitted' => 'public-plan'])
        ->and($this->operation->fresh()->input)->toBe($original);
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
});

it('admits native start while an obsolete PHP runtime lease is unavailable', function (): void {
    config(['dependency_detection.services_node_id' => null, 'operation_executor.worker_node_ids' => []]);
    $lease = Mockery::mock(ProcessRuntimeLease::class);
    app()->instance(ProcessRuntimeLease::class, $lease);
    $this->operation->update(['status' => OperationStatus::Succeeded]);
    $id = $this->postJson('/api/v1/processes/'.$this->process->id.'/start')->assertAccepted()->json('data.id');
    $admitted = Operation::query()->findOrFail($id);
    expect($admitted->state['process_control_writer'])->toBe('direct')
        ->and($admitted->state['swamp_run_id'])->toBe($this->runId)
        ->and($admitted->lock_key)->toBeNull()
        ->and($admitted->children()->count())->toBe(0);
});

it('refuses the removed polled start writer before recording intent', function (): void {
    config(['swamp.direct_writers' => ['process.start' => 'polled'], 'dependency_detection.services_node_id' => null, 'operation_executor.worker_node_ids' => []]);
    $before = Operation::query()->count();
    $this->postJson('/api/v1/processes/'.$this->process->id.'/start')->assertStatus(503)->assertJsonPath('error.code', 'operation.writer_invalid');
    expect(Operation::query()->count())->toBe($before)->and($this->socket->sent)->toBe([]);
});
