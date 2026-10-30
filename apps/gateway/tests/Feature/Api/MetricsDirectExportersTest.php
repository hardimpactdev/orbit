<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsEffectAdmission;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'gateway', 'platform' => 'linux', 'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.14', 'wireguard_ip' => '10.44.0.14', 'user' => 'orbit']);
    $gateway->roles()->create(['role' => 'gateway', 'status' => LifecycleStatus::Active]);
    $this->gateway = $gateway;
    $this->node = Node::query()->create(['name' => 'exporter', 'platform' => 'linux', 'architecture' => 'x86_64',
        'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.4', 'wireguard_ip' => '10.44.0.4', 'user' => 'orbit']);
    $this->node->accessibleNodes()->attach($gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id], 'swamp.direct_writers' => [], 'swamp.run_token' => 'fixture.shared']);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->run = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->operation = Operation::query()->create(['kind' => 'metrics.node_exporter_converge', 'target_type' => 'metrics_effect',
        'target_id' => $this->node->id, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'state' => ['metrics_exporter_writer' => 'direct', 'swamp_run_id' => $this->run],
        'input' => ['address' => '10.44.0.4', 'metrics_address' => '10.44.0.9', 'agent_sha256' => str_repeat('a', 64),
            'command' => 'foreign', 'password' => 'private-sentinel']]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-metrics-exporter', 'runId' => $this->run]]];
    app()->instance(WebSocketClient::class, $socket);
    $this->socket = $socket;
});

it('converts only Node exporter convergence and preserves its admitted writer and pin', function (): void {
    $direct = app(DirectOperation::class);
    foreach (['metrics.node_exporter_converge', 'metrics.node_exporter_remove', 'metrics.cadvisor_converge', 'metrics.cadvisor_remove'] as $kind) {
        expect($direct->writer($kind))->toBe($kind === 'metrics.node_exporter_converge' ? 'direct' : null);
    }
    config(['swamp.direct_writers' => ['metrics.node_exporter_converge' => 'direct']]);
    $manifest = tempnam(sys_get_temp_dir(), 'metrics-pin-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('c', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    $parent = new Operation(['kind' => 'metrics.reconcile']);
    $this->operation->delete();
    $admitted = app(MetricsEffectAdmission::class)->admit($parent, ['kind' => 'metrics.node_exporter_converge', 'node_id' => $this->node->id,
        'input' => ['address' => $this->node->wireguard_ip, 'metrics_address' => '10.44.0.9']]);
    expect($admitted->state['metrics_exporter_writer'])->toBe('direct')->and($admitted->input['agent_sha256'])->toBe(str_repeat('b', 64));
    expect($admitted->lock_key)->toBeNull();
    config(['swamp.direct_writers' => ['metrics.node_exporter_converge' => 'polled']]);
    $this->node->update(['wireguard_ip' => '10.44.0.40']);
    expect($direct->projection($admitted)->input($admitted))->toBe(['agent_sha256' => str_repeat('b', 64), 'address' => '10.44.0.4', 'metrics_address' => '10.44.0.9']);
});

it('binds the start response before the first Node read and exposes only admitted safe fields', function (): void {
    $this->operation->update(['state' => ['metrics_exporter_writer' => 'direct']]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
    app(StartSwampRun::class)->start($this->operation);
    $this->getJson($this->path.'?run_id='.$this->run)->assertOk()->assertJsonPath('data.input', [
        'agent_sha256' => str_repeat('a', 64), 'address' => '10.44.0.4', 'metrics_address' => '10.44.0.9',
    ])->assertDontSee('private-sentinel')->assertDontSee('foreign');
    expect($this->socket->sent)->toHaveCount(1);
    expect($this->socket->sent[0]['payload'])->toBe(['workflowIdOrName' => 'operation-metrics-exporter',
        'inputs' => ['operation_id' => $this->operation->id, 'kind' => 'metrics.node_exporter_converge', 'node_id' => $this->node->id],
        'runtimeTags' => ['orbit_start_key' => 'operation:'.$this->operation->id]]);
});

it('declares no Gateway effect relay and refuses foreign Node workers', function (): void {
    $this->postJson($this->path.'/primitive', ['run_id' => $this->run])->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
    $this->operation->update(['node_id' => $this->gateway->id]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertForbidden();
    $this->postJson($this->path.'/result', ['run_id' => $this->run, 'status' => 'running', 'started_at' => '2026-10-30T12:00:00.000Z'])->assertForbidden();
});

it('projects a bounded exporter result without leases claims or execution receipts', function (): void {
    $body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z',
        'finished_at' => '2026-10-30T12:00:01.000Z', 'result' => ['changed' => true, 'password' => 'private-sentinel']];
    $this->postJson($this->path.'/result', $body)->assertOk()->assertJsonPath('data.result', ['changed' => true])->assertDontSee('private-sentinel');
    $operation = $this->operation->fresh();
    expect($operation->attempts)->toBe(0)->and($operation->worker)->toBeNull();
    expect($operation->state)->not->toHaveKeys(['executor_runs', 'executor_lease_node_id', 'executor_effect_identity']);
    $this->postJson($this->path.'/result', $body)->assertOk();
    expect($operation->fresh()->sequence)->toBe($operation->sequence);
});

it('refuses missing success evidence mismatched refusal codes and invalid admitted pins', function (): void {
    $body = ['run_id' => $this->run, 'status' => 'succeeded', 'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z'];
    $this->postJson($this->path.'/result', [...$body, 'result' => []])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$body, 'status' => 'failed', 'result' => ['error_code' => 'metrics.exporter_service_failed'],
        'error_code' => 'metrics.exporter_configuration_failed'])->assertUnprocessable();
    $this->postJson($this->path.'/result', [...$body, 'result' => ['changed' => true, 'error_code' => 'private invalid diagnostic']])->assertUnprocessable();
    $this->operation->update(['input' => [...$this->operation->input, 'agent_sha256' => 'invalid']]);
    $this->getJson($this->path.'?run_id='.$this->run)->assertUnprocessable()->assertJsonPath('error.code', 'metrics.agent_pin_invalid');
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
});

it('refuses a direct admission whose address belongs to another Node', function (): void {
    config(['swamp.direct_writers' => ['metrics.node_exporter_converge' => 'direct']]);
    $before = Operation::query()->count();
    expect(fn () => app(MetricsEffectAdmission::class)->admit(new Operation(['kind' => 'metrics.reconcile']), [
        'kind' => 'metrics.node_exporter_converge', 'node_id' => $this->node->id,
        'input' => ['address' => '10.44.0.99', 'metrics_address' => '10.44.0.9'],
    ]))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe($before);
});

it('removes the previous Gateway agent execution and keyed completion contract for this kind', function (): void {
    $definition = OperationKinds::definitions()['metrics.node_exporter_converge'];
    expect($definition)->not->toHaveKeys(['runs', 'completion_contract', 'execution', 'completion']);
    expect(fn () => app(OperationExecutionContract::class)->authorizeRun($this->operation, [
        'worker' => 'obsolete', 'key' => 'node-exporter-converge', 'transport' => 'agent',
        'name' => 'metrics.node-exporter', 'input' => [],
    ]))->toThrow(ResourceOperationException::class);
    foreach (['metrics.node_exporter_remove', 'metrics.cadvisor_converge', 'metrics.cadvisor_remove'] as $kind) {
        expect(OperationKinds::definitions()[$kind])->toHaveKeys(['runs', 'completion_contract']);
    }
});

it('refuses an obsolete polled override before recording an exporter intent or calling Swamp', function (): void {
    config(['swamp.direct_writers' => ['metrics.node_exporter_converge' => 'polled']]);
    $count = Operation::query()->count();
    try {
        app(MetricsEffectAdmission::class)->admit(new Operation(['kind' => 'metrics.reconcile']), [
            'kind' => 'metrics.node_exporter_converge', 'node_id' => $this->node->id,
            'input' => ['address' => $this->node->wireguard_ip, 'metrics_address' => '10.44.0.9'],
        ]);
        test()->fail('Deleted exporter execution must never regain a polled writer.');
    } catch (ResourceOperationException $exception) {
        expect($exception->status)->toBe(503)->and($exception->errorCode)->toBe('operation.writer_invalid');
    }
    expect(Operation::query()->count())->toBe($count)->and($this->socket->sent)->toBe([]);
});

it('keeps an actual fleet child bound through direct start and terminal result projection', function (): void {
    $manifest = tempnam(sys_get_temp_dir(), 'metrics-child-pin-');
    file_put_contents($manifest, json_encode(['commit' => str_repeat('c', 40), 'version' => '0.5.0',
        'artifacts' => ['linux-x86_64' => ['sha256' => str_repeat('b', 64)]]], JSON_THROW_ON_ERROR));
    app()->instance(AgentDelivery::class, new AgentDelivery($manifest, sys_get_temp_dir()));
    $node = $this->node;
    $fleet = [['node_id' => $node->id, 'name' => $node->name, 'address' => $node->wireguard_ip,
        'selected' => true, 'caddy' => false, 'fpm' => false, 'hosts' => [], 'instance_ids' => []]];
    $parent = Operation::query()->create(['kind' => 'metrics.reconcile', 'target_type' => 'metrics', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'input' => ['fleet' => $fleet, 'fleet_node_ids' => [$node->id],
            'node_id' => $node->id, 'assignment_id' => 77, 'metrics_address' => $node->wireguard_ip,
            'gateway_node_id' => null, 'gateway_address' => null, 'dns_node_id' => null, 'remove' => false,
            'unreachable' => false, 'purge_data' => false, 'relocated' => false]]);
    $children = app(OperationChildren::class);
    $snapshot = DB::transaction(fn (): Operation => $children->admit($parent, ['key' => 'metrics-cancellation-boundary'])['operation']);
    $snapshot->update(['status' => 'succeeded', 'lock_key' => null]);
    $key = 'node_exporter-apply-'.$node->id;
    $child = DB::transaction(fn (): Operation => $children->admit($parent, ['key' => $key])['operation']);
    $child->refresh();
    expect($child->parent_operation_id)->toBe($parent->id)->and($child->child_key)->toBe($key)
        ->and($child->state['metrics_exporter_writer'])->toBe('direct')
        ->and($child->state['swamp_run_id'])->toBe($this->run)
        ->and($child->input['agent_sha256'])->toBe(str_repeat('b', 64));
    $this->getJson('/api/v1/direct-operations/'.$child->id.'?run_id='.$this->run)->assertOk()
        ->assertJsonPath('data.input.address', $node->wireguard_ip);
    $this->postJson('/api/v1/direct-operations/'.$child->id.'/result', ['run_id' => $this->run, 'status' => 'succeeded',
        'started_at' => '2026-10-30T12:00:00.000Z', 'finished_at' => '2026-10-30T12:00:01.000Z',
        'result' => ['changed' => true]])->assertOk();
    expect($children->requireSucceeded($parent, $key)->id)->toBe($child->id);
    expect($children->admit($parent, ['key' => $key])['created'])->toBeFalse();
});
