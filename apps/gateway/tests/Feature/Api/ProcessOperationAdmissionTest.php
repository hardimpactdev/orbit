<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessRuntimeLease;
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

it('starts creation directly without the old admission lease or claim contract', function (): void {
    app()->instance(ProcessRuntimeLease::class, Mockery::mock(ProcessRuntimeLease::class)->shouldNotReceive('run')->getMock());
    app()->instance(ProcessAdmissionLock::class, Mockery::mock(ProcessAdmissionLock::class)->shouldNotReceive('run')->getMock());
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->state['process_control_writer'])->toBe('direct')->and($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676')->and($operation->lock_key)->toBeNull();
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $this->postJson('/api/v1/operation-executor/claim', ['kind' => 'process.create', 'worker' => 'old'])->assertUnprocessable();
});

it('returns only admitted public installation input and binds private bytes to the current definition', function (): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'environment' => ['PASSWORD' => 'create-private-value']])->assertAccepted();
    $operation = Operation::query()->sole();
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $query = '?run_id='.$operation->state['swamp_run_id'];
    $response = $this->getJson($path.$query)->assertOk()->assertJsonPath('data.input.installation.process_id', $operation->target_id)
        ->assertJsonMissingPath('data.input.installation.definition')->assertJsonMissingPath('data.input.installation.content');
    expect($response->getContent())->not->toContain('create-private-value');
    $this->getJson($path.'/private-material'.$query)->assertOk()->assertJsonPath('data.installation.definition.environment.PASSWORD', 'create-private-value');
    $process = Process::query()->sole();
    $process->update(['runtime_config' => [...$process->runtime_config, 'image' => 'example:changed']]);
    $this->getJson($path.'/private-material'.$query)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('refuses a removed polled creation writer before storing a row', function (): void {
    config(['swamp.direct_writers' => ['process.create' => 'polled']]);
    $this->postJson('/api/v1/processes', $this->payload)->assertServiceUnavailable()->assertJsonPath('error.code', 'operation.writer_invalid');
    $this->assertDatabaseCount('processes', 0);
    $this->assertDatabaseCount('operations', 0);
});

it('captures the real first-visit systemd input with a boolean activation flag', function (): void {
    $this->postJson('/api/v1/processes', [
        'target_type' => 'node', 'target_id' => $this->node->id,
        'name' => 'native-first-visit', 'runtime' => 'systemd',
        'command' => ['/usr/bin/sleep', 'infinity'], 'start' => true,
    ])->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->input['activation_pending'])->toBeFalse();
    $input = $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$operation->state['swamp_run_id'])
        ->assertOk()->assertJsonPath('data.input.installation.activation_pending', false)->json('data.input');
    expect($input['installation']['content_hash'])->toBe($operation->input['content_hash']);
    foreach (['control', 'installation'] as $section) {
        $input[$section]['process_id'] = 12;
        $input[$section]['agent_sha256'] = str_repeat('a', 64);
        $input[$section]['name'] = 'orbit-process-12-native-first-visit.service';
    }
    $input['installation']['content_hash'] = str_repeat('b', 64);
    $fixture = json_decode(file_get_contents(base_path('tests/Fixtures/Processes/create-admitted-input.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($input)->toBe($fixture);
});
