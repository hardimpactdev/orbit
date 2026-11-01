<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmResumeAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmResumeIntent;
use App\Domain\TaskVms\TaskVmResumeProjection;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\TaskVms\UpCloudApi;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->host = Node::query()->create(['name' => 'resume-host', 'user' => 'nckrtl', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.13', 'public_ssh_host' => '192.0.2.13']);
    $this->guest = Node::query()->create(['name' => 'resume-guest', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.208', 'public_ssh_host' => '192.0.2.208']);
    $this->gateway = Node::query()->create(['name' => 'resume-gateway', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14']);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    foreach ([$this->host, $this->guest, $this->gateway] as $node) {
        $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG', 'ssh_host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode('AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG'), true)), '=')]);
    }
    config(['swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem', 'swamp.run_token' => 'fixture.fake']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)], 'piArtifactSha256' => str_repeat('c', 64)]);
    $this->vm = TaskVm::query()->create(['name' => 'pending', 'provider' => 'incus', 'state' => 'parked', 'host_node_id' => $this->host->id, 'node_id' => $this->guest->id, 'wireguard_ip' => $this->guest->wireguard_ip, 'pi_token' => 'private-pi-token']);
    $this->vm->update(['name' => 'tvm-'.$this->vm->id]);
    app()->instance(TaskVmProvider::class, Mockery::mock(TaskVmProvider::class));
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
});

function savedResumeIntent(): Operation
{
    $input = app(TaskVmResumeIntent::class)->capture(test()->vm);

    return Operation::query()->create(['kind' => 'task_vm.resume', 'target_type' => 'task_vm', 'target_id' => test()->vm->id,
        'node_id' => $input['node_id'], 'status' => 'queued', 'triggered_by' => 'fixture', 'input' => $input]);
}

it('freezes exact provider and guest placement without reading the host or starting the VM', function (): void {
    $before = $this->vm->fresh()->getRawOriginal();
    $operation = savedResumeIntent();
    $input = app(TaskVmResumeIntent::class)->input($operation);
    expect($input['intent']['node_id'])->toBe($this->host->id)->and($input['intent']['guest_node_id'])->toBe($this->guest->id)
        ->and($input['intent']['ssh_execution']['control_node_id'])->toBe(209)
        ->and($input['intent']['provider_input']['host_ssh_execution']['target']['host'])->toBe($this->host->wireguard_ip)
        ->and($input['intent']['provider_input']['vm'])->toBe(['project' => 'orbit-tasks', 'name' => $this->vm->name, 'pool' => 'orbit-tasks'])
        ->and($input['execution_secrets'])->toBe([])
        ->and(json_encode($operation->input))->not->toContain('private-pi-token')
        ->and($operation->input)->not->toHaveKeys(['pi_token', 'upcloud_token', 'private_bindings', 'participant_node_ids'])
        ->and($this->vm->fresh()->getRawOriginal())->toBe($before);
});

it('copies UpCloud API material through the shared vault and keeps Pi material guest-local', function (): void {
    $this->vm->update(['provider' => 'upcloud', 'host_node_id' => null, 'provider_id' => '8330af24-e530-48a0-810c-803f101c70ec']);
    app()->instance(UpCloudApi::class, new UpCloudApi(app(TaskVmSettings::class), 'ucat_resume_PRIVATE'));
    $operation = savedResumeIntent();
    $envelope = app(TaskVmResumeIntent::class)->input($operation);
    expect($operation->node_id)->toBe($this->gateway->id)
        ->and($envelope['execution_secrets'])->toBe(['upcloud' => ['vault' => 'orbit-control-inputs', 'key' => 'task-vm-upcloud-token']])
        ->and($envelope['intent']['provider_input']['vm'])->toBeNull()
        ->and(json_encode($operation->input))->not->toContain('ucat_resume_PRIVATE')->not->toContain('private-pi-token');
});

it('refuses moved VM ownership before exposing its input', function (array $changes): void {
    $operation = savedResumeIntent();
    $this->vm->update($changes === ['host_node_id' => 0] ? ['host_node_id' => $this->gateway->id] : $changes);
    expect(fn () => app(TaskVmResumeIntent::class)->input($operation))->toThrow(ResourceOperationException::class);
})->with([[['host_node_id' => 0]], [['provider_id' => '8330af24-e530-48a0-810c-803f101c70ec']]]);

it('reports lost resume eligibility without hiding an already terminal native result', function (): void {
    $operation = savedResumeIntent();
    $this->vm->update(['state' => 'destroying']);
    expect(app(TaskVmResumeIntent::class)->input($operation)['intent']['eligible'])->toBeFalse();
    $this->vm->update(['state' => 'ready']);
    expect(app(TaskVmResumeIntent::class)->input($operation)['intent']['eligible'])->toBeFalse();
});

it('refuses guest account and address drift and a mismatched parent kind', function (string $change): void {
    $operation = savedResumeIntent();
    match ($change) {
        'account' => $this->guest->update(['user' => 'other']),
        'address' => $this->guest->update(['wireguard_ip' => '10.44.0.207']),
        'kind' => $operation->update(['kind' => 'task_vm.destroy']),
    };
    expect(fn () => app(TaskVmResumeIntent::class)->owned($operation->fresh()))->toThrow(ResourceOperationException::class);
})->with(['account', 'address', 'kind']);

it('refuses incomplete runtime ownership rather than capturing a guessed readiness contract', function (string $change): void {
    if ($change === 'artifact') {
        TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)]]);
    } else {
        $this->vm->update([$change => null]);
    }
    expect(fn () => app(TaskVmResumeIntent::class)->capture($this->vm->fresh()))->toThrow(ResourceOperationException::class);
})->with(['artifact', 'pi_token', 'node_id']);

function prepareNativeResume(): void
{
    $run = '3fc3ad54-423a-4b4b-8187-99514098426b';
    config(['swamp.direct_writers' => ['task_vm.resume' => 'direct'], 'swamp.run_token' => 'fixture.fake',
        'dependency_detection.services_node_id' => test()->gateway->id]);
    test()->host->accessibleNodes()->attach(test()->gateway);
    test()->withServerVariables(['REMOTE_ADDR' => test()->host->wireguard_ip]);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-vm-resume', 'runId' => $run,
    ]]];
    app()->instance(WebSocketClient::class, $socket);
}

function admitNativeResume(): Operation
{
    prepareNativeResume();

    return app(StartTaskVmResumeAction::class)->execute(test()->vm->id);
}

it('admits and reuses the native resume from the ordinary wake caller without a PHP job', function (): void {
    prepareNativeResume();
    Queue::fake();

    expect(app(WakeTaskVmAction::class)->forVm($this->vm))->toBeFalse()
        ->and(app(WakeTaskVmAction::class)->forVm($this->vm->fresh()))->toBeFalse();

    $operation = Operation::query()->where('kind', 'task_vm.resume')->sole();
    expect($operation->target_id)->toBe($this->vm->id)
        ->and($operation->state['swamp_run_id'])->toBe('3fc3ad54-423a-4b4b-8187-99514098426b')
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Parked);
    Queue::assertNothingPushed();
});

function nativeResumeResult(Operation $operation, string $status): TestResponse
{
    return test()->postJson('/api/v1/direct-operations/'.$operation->id.'/result', [
        'run_id' => '3fc3ad54-423a-4b4b-8187-99514098426b', 'status' => $status,
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => $status === 'running' ? null : '2026-10-31T00:00:01.000Z',
        'error_code' => $status === 'failed' ? 'task_vm.resume_failed' : null,
        'result' => $status === 'succeeded' ? ['task_vm_id' => test()->vm->id, 'guest_node_id' => test()->guest->id, 'ready' => true, 'resumed' => true, 'ssh_ready' => true, 'ssh_host_key' => test()->guest->only(['ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint'])] : [],
    ]);
}

it('admits and deduplicates a bound native resume without starting PHP provider work', function (): void {
    $operation = admitNativeResume();
    expect($operation->kind)->toBe('task_vm.resume')
        ->and($operation->state['swamp_run_id'])->toBe('3fc3ad54-423a-4b4b-8187-99514098426b')
        ->and(app(StartTaskVmResumeAction::class)->execute($this->vm->id)->id)->toBe($operation->id);
});

it('projects native resume outcomes without provider work and refuses a late running result', function (string $status, TaskVmState $state): void {
    $operation = admitNativeResume();
    nativeResumeResult($operation, $status)->assertOk();
    expect($this->vm->fresh()->state)->toBe($state)->and($operation->fresh()->status)->toBe(OperationStatus::from($status));
    $before = $this->vm->fresh()->getRawOriginal();
    nativeResumeResult($operation, $status)->assertOk();
    nativeResumeResult($operation, 'running')->assertConflict();
    expect($this->vm->fresh()->getRawOriginal())->toBe($before);
})->with([['succeeded', TaskVmState::Ready], ['failed', TaskVmState::Failed], ['cancelled', TaskVmState::Parked]]);

it('records native success without overwriting a VM outside the parked state', function (string $state): void {
    $operation = admitNativeResume();
    $this->vm->update(['state' => $state]);
    $before = $this->vm->fresh()->getRawOriginal();
    nativeResumeResult($operation, 'succeeded')->assertOk();
    expect($this->vm->fresh()->getRawOriginal())->toBe($before)
        ->and($operation->fresh()->status)->toBe(OperationStatus::Succeeded);
})->with(['destroying', 'failed', 'ready']);

it('declares no private hydration or family callback for the converted resume', function (): void {
    $definition = app(DirectOperation::class)->definition('task_vm.resume');
    expect($definition['private_material'] ?? [])->toBe([])
        ->and($definition['workflow_inputs'])->toBeTrue()
        ->and($definition['terminal_result'])->toBe(['job' => 'resume', 'step' => 'result']);
});

it('refuses resume admission when its Incus host is inactive', function (): void {
    $this->host->update(['status' => LifecycleStatus::Removing]);
    expect(fn () => app(TaskVmResumeIntent::class)->capture($this->vm->fresh()))->toThrow(ResourceOperationException::class);
});

it('admits a fresh resume after an interrupted Operation releases its target', function (): void {
    $previous = savedResumeIntent();
    $previous->update(['status' => OperationStatus::Interrupted, 'lock_key' => null, 'finished_at' => now()]);
    $admitted = admitNativeResume();
    expect($admitted->id)->not->toBe($previous->id);
    expect($admitted->status)->toBe(OperationStatus::Running);
    expect($previous->fresh()->status)->toBe(OperationStatus::Interrupted);
});

it('captures missing guest pins through the trusted Incus host for existing ready VMs', function (): void {
    $this->vm->update(['state' => TaskVmState::Ready]);
    $this->guest->update(['ssh_host_key_type' => null, 'ssh_host_key' => null, 'ssh_host_fingerprint' => null]);
    $operation = savedResumeIntent();
    $input = app(TaskVmResumeIntent::class)->input($operation);
    expect($input['intent']['eligible'])->toBeTrue()
        ->and($input['intent']['ssh_execution'])->toBeNull()
        ->and($input['intent']['ssh_identity']['control_node_id'])->toBe(209)
        ->and($input['intent']['node']['wireguard_ip'])->toBe($this->vm->wireguard_ip);
});

it('records an observed host pin through the canonical endpoint action on resume success', function (): void {
    $pin = $this->guest->only(['ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint']);
    $this->guest->update(['ssh_host_key_type' => null, 'ssh_host_key' => null, 'ssh_host_fingerprint' => null]);
    $operation = admitNativeResume();
    app(TaskVmResumeProjection::class)->apply($operation, [
        'run_id' => '3fc3ad54-423a-4b4b-8187-99514098426b', 'status' => 'succeeded', 'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => null,
        'result' => ['task_vm_id' => $this->vm->id, 'guest_node_id' => $this->guest->id, 'resumed' => true, 'ready' => true, 'ssh_ready' => true, 'ssh_host_key' => $pin],
    ]);
    expect($this->guest->fresh()->only(array_keys($pin)))->toBe($pin);
});

it('rejects a changed recorded host pin without marking resume or VM successful', function (): void {
    $operation = admitNativeResume();
    $pin = $this->guest->only(['ssh_host_key_type', 'ssh_host_key', 'ssh_host_fingerprint']);
    $pin['ssh_host_fingerprint'] = 'SHA256:changed';
    expect(fn () => app(TaskVmResumeProjection::class)->apply($operation, [
        'run_id' => '3fc3ad54-423a-4b4b-8187-99514098426b', 'status' => 'succeeded',
        'started_at' => now()->toISOString(), 'finished_at' => now()->toISOString(), 'error_code' => null,
        'result' => ['task_vm_id' => $this->vm->id, 'guest_node_id' => $this->guest->id, 'resumed' => true, 'ready' => true, 'ssh_ready' => true, 'ssh_host_key' => $pin],
    ]))->toThrow(ResourceOperationException::class);
    expect($operation->fresh()->status)->toBe(OperationStatus::Running)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Parked);
});

it('admits convergence of a ready VM through the normal resume command', function (): void {
    $this->vm->update(['state' => TaskVmState::Ready]);
    prepareNativeResume();
    $this->artisan('task-vms:resume', ['vm' => (string) $this->vm->id])->assertSuccessful();
    $operation = Operation::query()->where('kind', 'task_vm.resume')->sole();
    expect($operation->input['admitted_state'])->toBe('ready');
    nativeResumeResult($operation, 'succeeded')->assertOk();
    expect($this->vm->fresh()->state)->toBe(TaskVmState::Ready);
});

it('does not turn an already ready VM into failed when SSH convergence fails', function (): void {
    $this->vm->update(['state' => TaskVmState::Ready]);
    $operation = admitNativeResume();
    nativeResumeResult($operation, 'failed')->assertOk();
    expect($this->vm->fresh()->state)->toBe(TaskVmState::Ready);
});
