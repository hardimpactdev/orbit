<?php

declare(strict_types=1);

use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Processes\ProcessAdmissionLock;
use App\Domain\Processes\ProcessOperationAdmission;
use App\Domain\Processes\ProcessOperationCompletion;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\NativeProcessRuntimeLease;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;

beforeEach(function (): void {
    $this->socket = $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => $request['type'] === 'vault.put'
        ? [['type' => 'vault.put', 'id' => $request['id'], 'payload' => ['data' => ['vaultName' => $request['payload']['vaultName'], 'secretKey' => $request['payload']['key'], 'vaultType' => 'local_encryption']]]]
        : [['type' => 'event', 'id' => $request['id'], 'event' => [
            'kind' => 'started', 'workflowName' => $request['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
        ]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'shared.scoped.fixture']);

    Queue::fake();
    $this->node = $this->markAsGateway(Node::query()->create([
        'name' => 'process-admission', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-host-key', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3',
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
        ->and($operation->input)->not->toHaveKeys(['installation', 'agent_sha256'])
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
    app()->instance(ProcessAdmissionLock::class, Mockery::mock(ProcessAdmissionLock::class)->shouldNotReceive('run')->getMock());
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    $operation = Operation::query()->sole();
    expect($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676')->and($operation->lock_key)->toBeNull();
    config(['dependency_detection.services_node_id' => $this->node->id]);
});

it('supplies full public Docker intent and only environment values through a native execution copy', function (): void {
    $secret = 'create-private-value';
    $this->postJson('/api/v1/processes', [...$this->payload, 'environment' => ['PASSWORD' => $secret]])->assertAccepted();
    $operation = Operation::query()->sole();
    config(['dependency_detection.services_node_id' => $this->node->id]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $query = '?run_id='.$operation->state['swamp_run_id'];
    $input = $this->getJson($path.$query)->assertOk()->assertDontSee($secret)->json('data.input');
    expect($input['intent']['installation']['definition']['image'])->toBe('example:1')
        ->and($input['intent']['installation']['definition']['command'])->toBe(['sleep', '300'])
        ->and($input['intent']['installation']['definition'])->not->toHaveKey('environment')
        ->and($input['execution_secrets']['environment'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'process-create-operation-'.$operation->id]);
    $puts = array_values(array_filter($this->socket->sent, fn (array $message): bool => $message['type'] === 'vault.put'));
    expect($puts)->toHaveCount(1)
        ->and(json_decode($puts[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR))->toBe(['PASSWORD' => $secret]);
    $runs = array_values(array_filter($this->socket->sent, fn (array $message): bool => $message['type'] === 'workflow.run'));
    expect($runs)->toHaveCount(1)->and($runs[0]['payload']['workflowIdOrName'])->toBe('operation-process-create')
        ->and(json_encode($runs[0], JSON_THROW_ON_ERROR))->not->toContain($secret);
    $this->getJson($path.'/private-material'.$query)->assertUnprocessable();
    Process::query()->sole()->update(['runtime_config' => [...Process::query()->sole()->runtime_config, 'image' => 'example:changed']]);
    $this->getJson($path.$query)->assertUnprocessable()->assertJsonPath('error.code', 'operation.execution_forbidden');
});

it('supplies a public first-visit systemd unit with no environment copy when none is needed', function (): void {
    $this->postJson('/api/v1/processes', [
        'target_type' => 'node', 'target_id' => $this->node->id,
        'name' => 'native-first-visit', 'runtime' => 'systemd',
        'command' => ['/usr/bin/sleep', 'infinity'], 'start' => true,
    ])->assertAccepted();
    $operation = Operation::query()->sole();
    $input = $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$operation->state['swamp_run_id'])
        ->assertOk()->json('data.input');
    $installation = $input['intent']['installation'];
    expect($installation['activation_pending'])->toBeTrue()
        ->and($installation['has_environment'])->toBeFalse()
        ->and($installation['unit'])->toContain('X-Orbit-Process-ID='.$operation->target_id, 'ExecStart=')
        ->and(hash('sha256', $installation['unit']))->toBe($installation['content_hash'])
        ->and($input['execution_secrets'])->toBe([])
        ->and($input['intent']['control'])->not->toHaveKey('agent_sha256');
});

it('passes public update intent and only environment bytes through its own native execution copy', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process = Process::query()->sole();
    $process->update(['status' => 'active']);
    app()->instance(ProcessAdmissionLock::class, Mockery::mock(ProcessAdmissionLock::class)->shouldNotReceive('run')->getMock());

    config(['dependency_detection.services_node_id' => $this->node->id, 'operation_executor.worker_node_ids' => [(string) $this->node->id]]);
    $secret = 'update-private-value';
    $this->patchJson('/api/v1/processes/'.$process->id, ['environment' => ['PASSWORD' => $secret], 'image' => 'example:updated'])
        ->assertAccepted()->assertJsonPath('data.kind', 'process.update')->assertDontSee($secret);

    $operation = Operation::query()->where('kind', 'process.update')->sole();
    expect($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676')
        ->and($operation->lock_key)->toBeNull()
        ->and($operation->input)->not->toHaveKeys(['installation', 'agent_sha256', 'environment', 'command', 'content', 'image'])
        ->and(json_encode($operation->toArray()))->not->toContain($secret);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $query = '?run_id='.$operation->state['swamp_run_id'];
    $input = $this->getJson($path.$query)->assertOk()->assertDontSee($secret)->json('data.input');
    expect($input['intent']['installation']['process_id'])->toBe($process->id)
        ->and($input['intent']['installation']['definition']['image'])->toBe('example:updated')
        ->and($input['intent']['installation']['definition'])->not->toHaveKey('environment')
        ->and($input['execution_secrets']['environment'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'process-update-operation-'.$operation->id]);
    $puts = array_values(array_filter($this->socket->sent, fn (array $message): bool => $message['type'] === 'vault.put'));
    expect($puts)->toHaveCount(1)
        ->and(json_decode($puts[0]['payload']['value'], true, flags: JSON_THROW_ON_ERROR))->toBe(['PASSWORD' => $secret]);
    $runs = array_values(array_filter($this->socket->sent, fn (array $message): bool => $message['type'] === 'workflow.run'));
    expect(end($runs)['payload']['workflowIdOrName'])->toBe('operation-process-create')
        ->and(end($runs)['payload']['inputs']['kind'])->toBe('process.update')
        ->and(json_encode(end($runs), JSON_THROW_ON_ERROR))->not->toContain($secret);
    $this->getJson($path.'/private-material'.$query)->assertUnprocessable();
    Queue::assertNothingPushed();
});

it('passes a changed systemd unit publicly while keeping managed update environment private', function (): void {
    $this->postJson('/api/v1/processes', [
        'target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'update-unit',
        'runtime' => 'systemd', 'command' => ['/usr/bin/true'], 'start' => false,
    ])->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process = Process::query()->sole();
    $secret = 'managed-systemd-update-private';
    $process->update(['status' => 'active', 'systemd_activation_pending' => false,
        'runtime_config' => [...$process->runtime_config, 'environment' => ['TOKEN' => $secret]]]);
    $this->patchJson('/api/v1/processes/'.$process->id, ['command' => ['/usr/bin/sleep', '45']])->assertAccepted();
    $operation = Operation::query()->where('kind', 'process.update')->sole();
    $input = $this->getJson('/api/v1/direct-operations/'.$operation->id.'?run_id='.$operation->state['swamp_run_id'])
        ->assertOk()->assertDontSee($secret)->json('data.input');
    $installation = $input['intent']['installation'];
    expect($installation['unit'])->toContain('ExecStart=', '45', 'EnvironmentFile=')
        ->and($installation['unit'])->not->toContain($secret)
        ->and(hash('sha256', $installation['unit']))->toBe($installation['content_hash'])
        ->and($installation['activation_pending'])->toBeTrue()
        ->and($installation['has_environment'])->toBeTrue()
        ->and($input['execution_secrets']['environment']['key'])->toBe('process-update-operation-'.$operation->id)
        ->and($operation->input)->not->toHaveKeys(['installation', 'agent_sha256']);
});

it('settles pending activation metadata from a bounded native update result', function (): void {
    $this->postJson('/api/v1/processes', [
        'target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'pending-update',
        'runtime' => 'systemd', 'command' => ['/usr/bin/true'], 'start' => false,
    ])->assertAccepted();
    $process = Process::query()->sole();
    $process->forceFill(['systemd_activation_pending' => true, 'retired_runtime' => 'docker'])->save();
    $operation = Operation::query()->sole();
    app(ProcessOperationCompletion::class)->complete($operation,
        OperationOutcome::succeeded(['state' => 'inactive']));
    expect($process->refresh()->systemd_activation_pending)->toBeFalse()
        ->and($process->systemd_activation_hash)->toBe($operation->input['content_hash'])
        ->and($process->retired_runtime)->toBeNull();
});

it('refuses update intent while a Route owns the Process reservation lock', function (): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process = Process::query()->sole();
    $process->update(['status' => 'active']);
    $before = $process->runtime_config;
    $reservation = new NativeProcessRuntimeLease;
    $reservation->run($process, function () use ($process, $before): void {
        $this->patchJson('/api/v1/processes/'.$process->id, ['image' => 'example:changed'])
            ->assertStatus(502)->assertJsonPath('error.code', 'process.runtime_lock_failed');
        expect($process->fresh()->runtime_config)->toBe($before);
        expect(Operation::query()->where('kind', 'process.update')->exists())->toBeFalse();
    });
    $this->patchJson('/api/v1/processes/'.$process->id, ['image' => 'example:changed'])->assertAccepted();
});

it('submits complete public control inputs and waits for native completion before changing desired state', function (string $verb): void {
    $this->postJson('/api/v1/processes', [...$this->payload, 'environment' => ['PASSWORD' => 'stop-private-value']])->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process = Process::query()->sole();
    $process->update(['status' => 'active', 'desired_state' => 'running']);

    $this->postJson('/api/v1/processes/'.$process->id.'/'.$verb)->assertAccepted()->assertJsonPath('data.kind', 'process.'.$verb);

    $socket = app(WebSocketClient::class);
    $request = array_last($socket->sent);
    expect($request['payload']['workflowIdOrName'])->toBe('operation-process-control');
    expect($request['payload']['inputs']['intent']['control'])->toEqual([
        'process_id' => $process->id, 'runtime' => 'docker',
        'name' => 'orbit-process-'.$process->id.'-throwaway', 'on_demand' => false, 'vite' => null,
    ]);
    expect($request['payload']['inputs']['intent']['ssh_execution']['target']['user'])->toBe('orbit');
    expect($request['payload']['inputs']['intent']['ssh_execution']['target'])->toBe(['name' => 'target', 'host' => $this->node->wireguard_ip, 'user' => 'orbit', 'port' => 22, 'host_keys' => ['ssh-ed25519 fixture-public-host-key']]);
    expect($request['payload']['inputs']['execution_secrets'])->toEqual((object) []);
    expect(json_encode($request))->not->toContain('stop-private-value');
    expect($process->refresh()->desired_state->value)->toBe('running');
    expect($process->status->value)->toBe('provisioning');
    Queue::assertNothingPushed();
})->with(['stop', 'start', 'restart']);

it('projects explicit stop from one terminal history read without a Gateway result callback', function (string $status): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    Operation::query()->sole()->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
    $process = Process::query()->sole();
    $process->update(['status' => 'active', 'desired_state' => 'running']);
    $this->postJson('/api/v1/processes/'.$process->id.'/stop')->assertAccepted();
    $operation = Operation::query()->where('kind', 'process.stop')->sole();
    $runId = $operation->state['swamp_run_id'];
    $history = ['id' => $runId, 'workflowName' => 'operation-process-control', 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => '2026-10-31T18:00:00Z', 'completedAt' => '2026-10-31T18:00:01Z',
        'jobs' => [['name' => 'runtime', 'steps' => [
            ['name' => 'Verify the state', ...($status === 'succeeded' ? ['outputs' => ['stdout' => '{"ready":true,"state":"exited"}']] : ['status' => 'pending'])],
        ]]],
    ];
    $this->socket = $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.read_token' => 'synthetic-native-reader']);

    $terminal = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $runId, 'status' => $status], ['job' => 'runtime', 'step' => 'Verify the state']);

    expect($terminal->status)->toBe(OperationStatus::from($status));
    expect($terminal->lock_key)->toBeNull();
    expect($terminal->result)->toBe($status === 'succeeded' ? ['ready' => true, 'state' => 'exited'] : ['error_code' => 'operation.native_failed']);
    expect($process->refresh()->desired_state->value)->toBe($status === 'succeeded' ? 'stopped' : 'running');
    expect($process->status->value)->toBe($status === 'succeeded' ? 'active' : 'failed');
    expect($socket->sent)->toHaveCount(1);
    expect($socket->sent[0]['type'])->toBe('workflow.history.get');
    Queue::assertNothingPushed();
})->with(['succeeded', 'interrupted']);

it('projects lifecycle terminal history and preserves a failed Process for fix forward', function (string $verb, string $status): void {
    $this->postJson('/api/v1/processes', $this->payload)->assertAccepted();
    $process = Process::query()->sole();
    $operation = Operation::query()->sole();
    if ($verb !== 'create') {
        $operation->update(['status' => OperationStatus::Succeeded, 'lock_key' => null]);
        $process->update(['status' => 'active']);
        if ($verb === 'destroy') {
            $this->deleteJson('/api/v1/processes/'.$process->id)->assertAccepted();
        } else {
            $this->patchJson('/api/v1/processes/'.$process->id, ['image' => 'example:updated'])->assertAccepted();
        }
        $operation = Operation::query()->where('kind', 'process.'.$verb)->sole();
    }
    $run = $operation->state['swamp_run_id'];
    $history = ['id' => $run, 'workflowName' => 'operation-process-'.($verb === 'update' ? 'create' : $verb), 'status' => $status,
        'inputs' => ['operation_id' => $operation->id, 'node_id' => $operation->node_id, 'kind' => $operation->kind],
        'startedAt' => '2026-11-01T00:00:00Z', 'completedAt' => '2026-11-01T00:00:01Z',
        'jobs' => [['name' => 'process', 'steps' => $status === 'failed' ? [] : [
            ['name' => 'Report the result', 'outputs' => ['stdout' => $verb === 'destroy' ? '{"ready":true}' : '{"ready":true,"state":"created"}']],
        ]]],
    ];
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $request): array => [['type' => 'workflow.history.get', 'id' => $request['id'], 'payload' => ['data' => $history]]];
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.read_token' => 'synthetic-native-reader']);
    $terminal = app(SwampCommandStep::class)->projectTerminal($operation, ['id' => $run, 'status' => $status], ['job' => 'process', 'step' => 'Report the result']);
    expect($terminal->status->value)->toBe($status)
        ->and($socket->sent)->toHaveCount(1)
        ->and($socket->sent[0]['type'])->toBe('workflow.history.get');
    if ($status === 'failed') {
        expect($terminal->error_code)->toBe('operation.native_failed')
            ->and($process->fresh())->not->toBeNull()
            ->and($process->fresh()->status->value)->toBe($verb === 'destroy' ? 'removing' : 'failed');
    } elseif ($verb === 'destroy') {
        expect($process->fresh())->toBeNull();
    } else {
        expect($process->fresh()->status->value)->toBe('active');
    }
})->with([['create', 'succeeded'], ['create', 'failed'], ['update', 'succeeded'], ['update', 'failed'], ['destroy', 'succeeded'], ['destroy', 'failed']]);

it('keeps activation pending until native acceptance records the rendered unit hash', function (): void {
    $this->postJson('/api/v1/processes', [
        'target_type' => 'node', 'target_id' => $this->node->id, 'name' => 'activation-proof',
        'runtime' => 'systemd', 'command' => ['/bin/sleep', '30'], 'start' => true,
    ])->assertAccepted();
    $process = Process::query()->sole();
    $first = Operation::query()->sole();
    expect($first->input['activation_pending'])->toBeTrue();
    app(ProcessOperationCompletion::class)->complete($first, OperationOutcome::failed('operation.native_failed'));
    $retry = app(ProcessOperationAdmission::class)->execute($process->refresh(), 'create');
    expect($retry->input['activation_pending'])->toBeTrue();
    app(ProcessOperationCompletion::class)->complete($retry, OperationOutcome::succeeded(['ready' => true, 'state' => 'active']));
    $compliant = app(ProcessOperationAdmission::class)->execute($process->refresh(), 'create');
    expect($compliant->input['activation_pending'])->toBeFalse()
        ->and($process->systemd_activation_hash)->toBe($compliant->input['content_hash']);
});
