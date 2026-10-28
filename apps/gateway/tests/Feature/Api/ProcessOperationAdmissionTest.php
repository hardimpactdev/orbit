<?php

declare(strict_types=1);

use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    Queue::fake();
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'process-admission', 'status' => 'active', 'platform' => 'linux',
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
})->with(['start', 'stop', 'restart']);

it('rolls back competing intent before it changes a Process specification', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    $process = Process::query()->sole();
    $this->patchJson('/api/v1/processes/'.$process->id, ['image' => 'example:2'])->assertConflict()->assertJsonPath('error.code', 'operation.target_busy');
    expect($process->refresh()->runtime_config['image'])->toBe('example:1');
});

it('enforces exact lifecycle run contracts and scalar result redaction', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    $operation->kind = 'process.stop';
    $contract = app(OperationExecutionContract::class);
    $input = array_intersect_key($operation->input, array_flip(['process_id', 'runtime', 'name', 'on_demand']));
    $contract->authorizeRun($operation, ['worker' => 'worker', 'key' => 'stop', 'transport' => 'agent', 'name' => 'process.control', 'input' => [...$input, 'operation' => 'stop']]);
    expect(fn () => $contract->authorizeRun($operation, ['worker' => 'worker', 'key' => 'stop', 'transport' => 'agent', 'name' => 'process.control', 'input' => [...$input, 'operation' => 'restart']]))
        ->toThrow(ResourceOperationException::class);
    expect($contract->result($operation, ['state' => 'active TOKEN=private-receipt', 'environment' => ['X' => 'private'], 'raw' => 'private']))
        ->toBe(['state' => 'active TOKEN=[REDACTED]']);
});
