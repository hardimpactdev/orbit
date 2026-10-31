<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationExecutors;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\FakeAgentCommandRunner;

beforeEach(function (): void {
    $gateway = Node::query()->create(['name' => 'discard-gateway', 'public_ssh_host' => '192.0.2.1', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.1']);
    $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $this->node = Node::query()->create(['name' => 'discard-destination', 'public_ssh_host' => '192.0.2.9', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active', 'wireguard_ip' => '10.44.0.9']);
    $this->node->accessibleNodes()->attach($gateway);
    config(['operation_executor.worker_node_ids' => [$this->node->id], 'dependency_detection.services_node_id' => null]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->runId = 'a075eb23-7ac4-4487-80d1-57a038a92676';
    $this->intent = ['agent_sha256' => str_repeat('a', 64), 'destination' => '/srv/apps/example/proof'];
    $this->operation = Operation::query()->create(['kind' => 'instance.transfer_discard', 'target_type' => 'instance',
        'target_id' => 117, 'node_id' => $this->node->id, 'status' => OperationStatus::Queued, 'sequence' => 1,
        'input' => ['instance_id' => 117, 'transfer_id' => 51],
        'state' => ['instance_lifecycle_writer' => 'direct', 'swamp_run_id' => $this->runId, 'transfer_discard_intent' => $this->intent]]);
    $this->path = '/api/v1/direct-operations/'.$this->operation->id;
    $this->body = ['run_id' => $this->runId, 'status' => 'succeeded', 'started_at' => '2026-10-30T19:00:00.000Z',
        'finished_at' => '2026-10-30T19:00:01.000Z', 'result' => ['discarded' => true]];
});

it('uses the direct writer by default and reads only the recorded discard intent', function (): void {
    config(['swamp.direct_writers' => []]);
    expect(app(DirectOperation::class)->writer('instance.transfer_discard'))->toBe('direct');
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.input', $this->intent);
});

it('projects successful cleanup without a lease or a Gateway command and replays it without loading the target', function (): void {
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);
    $this->postJson($this->path.'/primitive', ['run_id' => $this->runId])->assertUnprocessable();
    $this->postJson($this->path.'/result', $this->body)->assertOk()->assertJsonPath('data.result.discarded', true);
    $sequence = $this->operation->fresh()->sequence;
    $this->postJson($this->path.'/result', $this->body)->assertOk();
    expect($this->operation->fresh()->sequence)->toBe($sequence);
    expect($this->operation->fresh()->lock_key)->toBeNull();
    expect($this->operation->fresh()->attempts)->toBe(0);
    expect($agents->calls)->toBe([]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertOk()->assertJsonPath('data.status', 'succeeded');
});

it('refuses malformed success before storing a cleanup result', function (array $result): void {
    $this->postJson($this->path.'/result', [...$this->body, 'result' => $result])->assertUnprocessable();
    expect($this->operation->fresh()->status)->toBe(OperationStatus::Queued);
})->with(['missing' => [[]], 'false' => [['discarded' => false]], 'extra path' => [['discarded' => true, 'destination' => '/private']]]);

it('projects only a bounded refusal or cancellation', function (string $status): void {
    $code = $status === 'failed' ? 'instance.transfer_source_failed' : null;
    $result = $code === null ? [] : ['error_code' => $code];
    $this->postJson($this->path.'/result', [...$this->body, 'status' => $status, 'result' => $result, 'error_code' => $code])
        ->assertOk()->assertJsonPath('data.status', $status);
})->with(['failed', 'cancelled']);

it('refuses an unbound run, a different root, and a foreign Node before reading cleanup input', function (): void {
    $this->getJson($this->path.'?run_id=fabd4059-fb6c-49d8-940e-01ae58aa3f38')->assertConflict();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.254']);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertForbidden();
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->operation->update(['state' => ['instance_lifecycle_writer' => 'direct', 'transfer_discard_intent' => $this->intent]]);
    $this->getJson($this->path.'?run_id='.$this->runId)->assertConflict()->assertJsonPath('error.code', 'operation.run_unbound');
});

it('refuses the removed writer instead of admitting an unexecutable cleanup', function (): void {
    config(['swamp.direct_writers' => ['instance.transfer_discard' => 'polled']]);
    expect(fn () => app(DirectOperation::class)->writer('instance.transfer_discard'))
        ->toThrow(ResourceOperationException::class);
});

it('has no polled cleanup executor or Gateway receipt contract', function (): void {
    $kinds = app(OperationKinds::class);
    expect($kinds->directOnly('instance.transfer_discard'))->toBeTrue();
    expect(app(OperationExecutors::class)->polled('instance.transfer_discard'))->toBeFalse();
    expect($kinds->definition('instance.transfer_discard'))->not->toHaveKeys(['runs', 'execution', 'completion', 'completion_contract']);
});
