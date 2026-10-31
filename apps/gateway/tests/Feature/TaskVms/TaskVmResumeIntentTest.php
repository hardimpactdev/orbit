<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmResumeAction;
use App\Actions\TaskVms\WakeTaskVmAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmResumeIntent;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\TaskVms\TaskVmSecretSource;
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
    expect($input['node_id'])->toBe($this->host->id)->and($input['guest_node_id'])->toBe($this->guest->id)
        ->and($input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->host))
        ->and($input['guest_agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->guest))
        ->and($input['pi_url'])->toBe('http://'.$this->guest->wireguard_ip.':3774')
        ->and($input['vm'])->toBe(['project' => 'orbit-tasks', 'name' => $this->vm->name, 'cidr' => '10.251.77.0/24'])
        ->and($operation->input['participant_node_ids'])->toBe([$this->host->id, $this->guest->id])
        ->and($operation->input['private_bindings']['pi_token'])->toBe(['owner_node_id' => $this->guest->id, 'read_node_ids' => [$this->host->id], 'write_node_ids' => []])
        ->and($input)->not->toHaveKeys(['pi_token', 'upcloud_token', 'private_bindings', 'participant_node_ids'])
        ->and(json_encode($operation->input))->not->toContain('private-pi-token')
        ->and($this->vm->fresh()->getRawOriginal())->toBe($before);
});

it('keeps UpCloud API and Pi references on the owning Gateway host without public token bytes', function (): void {
    $this->vm->update(['provider' => 'upcloud', 'host_node_id' => null, 'provider_id' => '8330af24-e530-48a0-810c-803f101c70ec']);
    $operation = savedResumeIntent();
    expect($operation->node_id)->toBe($this->gateway->id)
        ->and($operation->input['participant_node_ids'])->toBe([$this->gateway->id, $this->guest->id])
        ->and($operation->input['private_bindings']['pi_token'])->toBe(['owner_node_id' => $this->guest->id, 'read_node_ids' => [$this->gateway->id], 'write_node_ids' => []])
        ->and($operation->input['pi_token'])->toBe(['$secret' => 'task_vm.pi_token', 'id' => $this->vm->id])
        ->and(app(TaskVmResumeIntent::class)->input($operation)['vm'])->toBeNull();
});

it('refuses moved VM ownership before exposing its input', function (array $changes): void {
    $operation = savedResumeIntent();
    $this->vm->update($changes === ['host_node_id' => 0] ? ['host_node_id' => $this->gateway->id] : $changes);
    expect(fn () => app(TaskVmResumeIntent::class)->input($operation))->toThrow(ResourceOperationException::class);
})->with([[['host_node_id' => 0]], [['provider_id' => '8330af24-e530-48a0-810c-803f101c70ec']]]);

it('reports lost resume eligibility without hiding an already terminal native result', function (): void {
    $operation = savedResumeIntent();
    $this->vm->update(['state' => 'destroying']);
    expect(app(TaskVmResumeIntent::class)->input($operation)['eligible'])->toBeFalse();
    $this->vm->update(['state' => 'ready']);
    expect(app(TaskVmResumeIntent::class)->input($operation)['eligible'])->toBeFalse();
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
        'result' => $status === 'succeeded' ? ['task_vm_id' => test()->vm->id, 'guest_node_id' => test()->guest->id, 'ready' => true] : [],
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

it('reads only the exact provider credentials through the bound private callback', function (string $provider): void {
    if ($provider === 'upcloud') {
        $this->vm->update(['provider' => 'upcloud', 'host_node_id' => null, 'provider_id' => '8330af24-e530-48a0-810c-803f101c70ec']);
        TaskVmSettingsBuilder::bind(['piArtifactSha256' => str_repeat('c', 64), 'upcloud' => new TaskVmUpCloud(false, [], '/fixture/upcloud', 1, 'fi-hel1', '1xCPU-2GB', 20, '00000000-0000-4000-8000-000000000001')]);
        app()->instance(UpCloudApi::class, new UpCloudApi(app(TaskVmSettings::class), 'ucat_resume_PRIVATE'));
    }
    $operation = admitNativeResume();
    $this->withServerVariables(['REMOTE_ADDR' => $provider === 'upcloud' ? $this->gateway->wireguard_ip : $this->host->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $response = $this->getJson($path.'/private-material?run_id=3fc3ad54-423a-4b4b-8187-99514098426b')->assertOk();
    $response->assertJsonPath('data.pi_token', 'private-pi-token');
    if ($provider === 'upcloud') {
        $response->assertJsonPath('data.upcloud_token', 'ucat_resume_PRIVATE');
    } else {
        $response->assertJsonPath('data.upcloud_token', null);
    }
    $this->getJson($path.'?run_id=3fc3ad54-423a-4b4b-8187-99514098426b')->assertOk()
        ->assertJsonMissing(['pi_token' => 'private-pi-token'])->assertJsonMissing(['upcloud_token' => 'ucat_resume_PRIVATE']);
    $this->getJson($path.'/private-material?run_id=920c3e86-2e22-46ce-9716-91aa0c3eec46')->assertConflict();
    $this->guest->update(['user' => 'foreign']);
    $this->getJson($path.'/private-material?run_id=3fc3ad54-423a-4b4b-8187-99514098426b')->assertUnprocessable();
})->with(['incus', 'upcloud']);

it('refuses private hydration after the admitted Incus host changes', function (): void {
    $operation = savedResumeIntent();
    $operation->update(['node_id' => $this->gateway->id]);
    $source = new TaskVmSecretSource('task_vm.pi_token');
    expect(fn () => $source->resolve($operation, $this->vm->id, '', 'pi_token'))->toThrow(ResourceOperationException::class);
});

it('refuses resume admission when its Incus host is inactive', function (): void {
    $this->host->update(['status' => LifecycleStatus::Removing]);
    expect(fn () => app(TaskVmResumeIntent::class)->capture($this->vm->fresh()))->toThrow(ResourceOperationException::class);
});
