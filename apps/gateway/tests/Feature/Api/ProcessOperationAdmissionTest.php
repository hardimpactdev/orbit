<?php

declare(strict_types=1);

use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-process-control', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);

    Queue::fake();
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'process-admission', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    $this->payload = ['target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'throwaway',
        'runtime' => 'docker', 'image' => 'example:1', 'command' => ['sleep', '300']];
});

it('records Process intent without dispatch or exposing environment bytes', function (): void {
    $secret = 'operation-admission-private-value';
    $response = $this->withHeader('Idempotency-Key', 'process-proof')->postJson('/api/v1/processes', [...$this->payload, 'environment' => ['PASSWORD' => $secret]])
        ->assertAccepted()->assertJsonPath('data.kind', 'process.create');
    $operation = Operation::query()->sole();
    expect($operation->node_id)->toBe($this->node->id)
        ->and($operation->input['environment'])->toBe(['$secret' => 'process.environment', 'id' => Process::query()->sole()->id])
        ->and(json_encode($operation->toArray()))->not->toContain($secret)
        ->and($response->getContent())->not->toContain($secret)
        ->and(Process::query()->sole()->status->value)->toBe('provisioning');
    $this->postJson('/api/v1/processes', [...$this->payload, 'environment' => ['PASSWORD' => $secret]])->assertAccepted()->assertJsonPath('data.id', $operation->id);
    Queue::assertNothingPushed();
});

it('keeps changed-name admission and update omissions in the Gateway', function (): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'environment' => ['A' => 'private']])->assertAccepted();
    $process = Process::query()->sole();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process->update(['status' => 'active']);
    $this->postJson('/api/v1/processes', [...$this->payload, 'image' => 'example:2'])->assertConflict()->assertJsonPath('error.code', 'process.name_taken');
    $this->patchJson('/api/v1/processes/'.$process->id, ['keep_alive' => true])->assertAccepted()->assertJsonPath('data.kind', 'process.update');
    expect($process->refresh()->runtime_config['image'])->toBe('example:1')
        ->and($process->runtime_config['environment'])->toBe(['A' => 'private'])
        ->and($process->desired_state->value)->toBe('stopped');
});

it('records lifecycle intent without marking an unexecuted effect successful', function (string $verb): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process = Process::query()->sole();
    $process->update(['status' => 'active']);
    $this->postJson('/api/v1/processes/'.$process->id.'/'.$verb)->assertAccepted()->assertJsonPath('data.kind', 'process.'.$verb);
    expect($process->refresh()->desired_state->value)->toBe('stopped')
        ->and($process->status->value)->toBe('provisioning');
    Queue::assertNothingPushed();
})->with(['start', 'restart']);

it('rolls back competing intent before it changes a Process specification', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    $process = Process::query()->sole();
    $this->patchJson('/api/v1/processes/'.$process->id, ['image' => 'example:2'])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect($process->refresh()->runtime_config['image'])->toBe('example:1');
});

it('reads a Process receipt before its first effect and rejects readiness without proof', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    $executor = Node::query()->create(['name' => 'separate-executor', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.4', 'public_ssh_host' => '192.0.2.4']);
    $executor->accessibleNodes()->attach($this->node);
    config(['dependency_detection.services_node_id' => $executor->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $executor->wireguard_ip]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'process.create', 'worker' => 'process-worker'])->assertOk();
    $path = '/api/v1/operation-executor/'.$operation->id;
    $this->getJson($path.'/receipts/process-converge-container')->assertOk()->assertJsonPath('data.state', 'unknown');
    $this->postJson($path.'/run', ['worker' => 'process-worker', 'key' => 'runtime-ready', 'transport' => 'gateway', 'name' => 'process.runtime_ready', 'input' => ['process_id' => $operation->target_id]])->assertConflict()->assertJsonPath('error.code', 'operation.completion_unproven');
    expect($operation->refresh()->state['executor_runs']['runtime-ready']['result'])->toBeNull();
});

it('can finish proof after cancellation once its native effect is proven', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    $operation->update(['cancel_requested_at' => now(), 'state' => ['executor_runs' => ['process-converge-container' => ['transport' => 'agent', 'name' => 'process.converge-container', 'result' => ['state' => 'inactive']]]]]);
    expect(app(OperationExecutionContract::class)->cancellationAllowsRun($operation))->toBeTrue();
    $operation->state = ['executor_runs' => ['process-converge-container' => ['transport' => 'agent', 'name' => 'process.converge-container', 'result' => null]]];
    expect(app(OperationExecutionContract::class)->cancellationAllowsRun($operation))->toBeFalse();
});
