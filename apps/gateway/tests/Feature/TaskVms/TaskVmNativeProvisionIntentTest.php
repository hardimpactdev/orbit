<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\StartTaskVmProvisionAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmEnrollmentWorkflowInput;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmProvisionProjection;
use App\Domain\TaskVms\TaskVmProvisionWorkflowInputs;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmRecipeImages;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->host = Node::query()->create(['name' => 'provision-host', 'user' => 'nckrtl', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.13', 'public_ssh_host' => '192.0.2.13']);
    $this->gateway = Node::query()->create(['name' => 'provision-gateway', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.14', 'public_ssh_host' => '192.0.2.14']);
    $this->gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)], 'devClusterId' => null,
        'piArtifactPath' => '/private/pi', 'piArtifactSha256' => str_repeat('c', 64), 'piModels' => ['test-model']]);
    $model = Mockery::mock(ModelKeyIssuer::class);
    $model->shouldReceive('origin')->andReturn(TaskVmSettingsBuilder::Origin);
    $model->shouldReceive('assertReady');
    $model->shouldReceive('ownerNodeId')->andReturn($this->gateway->id);
    app()->instance(ModelKeyIssuer::class, $model);
    $certificates = Mockery::mock(LeafCertificateSigner::class);
    $certificates->shouldReceive('rootCertificate')->andReturn('ROOT CERTIFICATE');
    app()->instance(LeafCertificateSigner::class, $certificates);
    $this->issuer = Node::query()->create(['name' => 'provision-issuer', 'user' => 'orbit', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.209']);
    config(['dependency_detection.services_node_id' => $this->issuer->id]);
    $vpn = Node::query()->create(['name' => 'provision-vpn', 'user' => 'orbit', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active, 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1']);
    $vpn->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    $home = storage_path('provision-vpn');
    File::ensureDirectoryExists($home.'/wireguard');
    File::put($home.'/wireguard/private.key', base64_encode(str_repeat('s', 32)));
    File::put($home.'/wireguard/public.key', base64_encode(str_repeat('p', 32)));
    app()->instance(VpnConfigurationRepository::class, new VpnConfigurationRepository(app(VpnSettings::class), $home));
    $project = Project::query()->create(['name' => 'Provision', 'slug' => 'native-provision', 'repository_url' => 'https://github.com/example/provision.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Provision', 'brief' => 'Native provision', 'status' => 'reserved', 'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $this->vm = TaskVm::query()->create(['name' => 'pending', 'provider' => 'incus', 'state' => 'provisioning', 'host_node_id' => $this->host->id,
        'group_id' => $group->id, 'pi_token' => str_repeat('d', 64), 'wireguard_ip' => '10.44.0.208', 'image_fingerprint' => str_repeat('a', 64), 'recipe_identity' => 'sha256:'.str_repeat('b', 64)]);
    $this->vm->update(['name' => 'tvm-'.$this->vm->id]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 '.base64_encode(str_repeat('a', 32)));
    app()->instance(SshKeyProvider::class, $keys);
    app()->instance(TaskVmProvider::class, Mockery::mock(TaskVmProvider::class));
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));

});

it('composes the canonical enrollment public capture with its actual provider allocation', function (): void {
    $allocation = app(TaskVmProvisionIntent::class)->capture($this->vm);
    $enrollment = app(TaskVmEnrollmentWorkflowInput::class);
    $public = $enrollment->fromCaptured($allocation['enrollment'], $allocation, ['address' => null, 'public_key' => null]);
    $inputs = app(TaskVmProvisionWorkflowInputs::class)->compose($allocation, $enrollment->envelope($public));

    expect($inputs['intent'])->toMatchArray(['task_vm_id' => $this->vm->id,
        'node_id' => $this->host->id, 'execution_node_id' => $this->host->id,
        'guest_node_id' => $allocation['guest_node_id'], 'vm' => $allocation['vm'],
        'runtime' => $public['runtime'], '_wireguard' => $public['_wireguard']])
        ->and($inputs['execution_secrets'])->toBe([]);
    foreach (['$secret', 'private_bindings', 'read_node_ids', 'write_node_ids', 'upcloud_token'] as $private) {
        expect(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain($private);
    }
});

it('captures the full command envelope with canonical execution copies and no private values', function (): void {
    config(['swamp.run_token' => 'synthetic-start-token', 'swamp.ca_path' => '/public/root.pem', 'swamp.vault_server' => 'wss://provision-command.test']);
    $issuer = Mockery::mock(ModelKeyIssuer::class);
    $issuer->shouldReceive('origin')->andReturn(TaskVmSettingsBuilder::Origin);
    $issuer->shouldReceive('ownerNodeId')->andReturn($this->gateway->id);
    $issuer->shouldReceive('assertReady');
    $issuer->shouldReceive('privateMaterial')->andReturn(['management_key' => 'synthetic-management-key', 'anchor_key' => str_repeat('2', 64)]);
    app()->instance(ModelKeyIssuer::class, $issuer);
    $writes = [];
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->times(4)->andReturnUsing(function (ProcessInvocation $call) use (&$writes): CommandResult {
        $protected = $call->protectedInput ?? throw new LogicException('The shared copy must use protected input.');
        $value = stream_get_contents($protected->stream());
        expect(json_encode($call->arguments, JSON_THROW_ON_ERROR))->not->toContain($value);
        $writes[] = $call->arguments[4];

        return new CommandResult(0, '{}', '', 1, false);
    });
    app()->instance(ProcessRunner::class, $runner);
    $allocation = app(TaskVmProvisionIntent::class)->capture($this->vm);

    $inputs = app(TaskVmProvisionWorkflowInputs::class)->capture($this->vm, $allocation);
    $again = app(TaskVmProvisionWorkflowInputs::class)->capture($this->vm, $allocation);

    expect($again)->toBe($inputs)
        ->and($inputs['intent'])->toMatchArray(['task_vm_id' => $this->vm->id, 'node_id' => $this->host->id,
            'guest_node_id' => $allocation['guest_node_id'], 'vm' => $allocation['vm'], 'create' => $allocation['create']])
        ->and(array_keys($inputs['execution_secrets']))->toBe(['model_key', 'model_router_management', 'model_router_anchor', 'pi_token'])
        ->and($writes)->toBe(['task-vm-model-key-'.$this->vm->id, 'model-router-management-node-'.$this->gateway->id,
            'model-router-anchor-node-'.$this->gateway->id, 'task-vm-pi-token-'.$this->vm->id]);
    expect(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain($this->vm->model_key, $this->vm->pi_token,
        'synthetic-management-key', str_repeat('2', 64), 'private_bindings', '$secret');
    expect(Operation::query()->count())->toBe(0);
});

it('refuses the command envelope when the shared vault cannot accept execution copies', function (): void {
    config(['swamp.run_token' => 'synthetic-start-token', 'swamp.ca_path' => '/public/root.pem', 'swamp.vault_server' => 'wss://provision-refused.test']);
    $issuer = Mockery::mock(ModelKeyIssuer::class);
    $issuer->shouldReceive('origin')->andReturn(TaskVmSettingsBuilder::Origin);
    $issuer->shouldReceive('ownerNodeId')->andReturn($this->gateway->id);
    $issuer->shouldReceive('assertReady');
    $issuer->shouldReceive('privateMaterial')->once()->andReturn(['management_key' => 'synthetic-management-key', 'anchor_key' => str_repeat('2', 64)]);
    app()->instance(ModelKeyIssuer::class, $issuer);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->once()->andReturn(new CommandResult(1, '', 'synthetic-private-failure', 1, false));
    app()->instance(ProcessRunner::class, $runner);
    $allocation = app(TaskVmProvisionIntent::class)->capture($this->vm);

    try {
        app(TaskVmProvisionWorkflowInputs::class)->capture($this->vm, $allocation);
        test()->fail('Unavailable execution copies must prevent the command envelope.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('swamp.vault_sync_unavailable')
            ->and((string) $error)->not->toContain('synthetic-private-failure')
            ->and($error->getPrevious())->toBeNull();
    }
    expect(Operation::query()->count())->toBe(0)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning);
});

it('checks the captured root executor before requesting any execution copies', function (): void {
    $allocation = app(TaskVmProvisionIntent::class)->capture($this->vm);
    $allocation['node_id'] = $this->issuer->id;
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(ProcessRunner::class, $runner);

    try {
        app(TaskVmProvisionWorkflowInputs::class)->capture($this->vm, $allocation);
        test()->fail('A different root executor must refuse before requesting secrets.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('task_vm.provision_refused');
    }
    expect(Operation::query()->count())->toBe(0);
});

function savedNativeProvisionIntent(): Operation
{
    $input = app(TaskVmProvisionIntent::class)->capture(test()->vm);

    return Operation::query()->create(['kind' => 'task_vm.provision', 'target_type' => 'task_vm', 'target_id' => test()->vm->id,
        'node_id' => $input['node_id'], 'status' => 'queued', 'triggered_by' => 'fixture', 'input' => $input,
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
}

it('freezes image and reserves exact guest and Process intent without contacting a provider', function (): void {
    $operation = savedNativeProvisionIntent();
    $input = app(TaskVmProvisionIntent::class)->input($operation);
    expect($input['node_id'])->toBe($this->host->id)->and($input['guest_node_id'])->toBe($this->vm->fresh()->node_id)
        ->and($input['create']['image'])->toBe($this->vm->image_fingerprint)
        ->and($input['create']['expected_fingerprint'])->toBe($this->vm->image_fingerprint)
        ->and($input['agent_sha256'])->toBe(app(AgentDelivery::class)->pin($this->host))
        ->and($input['vm']['name'])->toBe($this->vm->name)->and($input['eligible'])->toBeTrue()
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning)
        ->and($input['enrollment']['runtime']['process']['process_id'])->toBeInt()
        ->and(Node::query()->count())->toBe(5)
        ->and($input['issuer_node_id'])->toBe($this->issuer->id)
        ->and($input['participant_node_ids'])->toContain($this->host->id, $this->gateway->id, $this->issuer->id, $input['guest_node_id'])
        ->and($input)->not->toHaveKey('network')
        ->and($input['enrollment']['network']['address'])->toStartWith($this->vm->wireguard_ip)
        ->and($input['private_bindings']['installation']['owner_node_id'])->toBe($input['guest_node_id'])
        ->and(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('d', 64))->not->toContain($this->vm->fresh()->model_key);
});

it('keeps UpCloud credentials as an existing private reference on the exact Gateway worker', function (): void {
    TaskVmSettingsBuilder::bind(['devClusterId' => null, 'piArtifactPath' => '/private/pi', 'piArtifactSha256' => str_repeat('c', 64), 'piModels' => ['test-model'], 'upcloud' => new TaskVmUpCloud(true, [], '/fixture/token', 1, 'fi-hel1', '1xCPU-2GB', 20, '00000000-0000-4000-8000-000000000001')]);
    $this->vm->update(['provider' => 'upcloud', 'host_node_id' => null, 'image_fingerprint' => 'bb757fe7-0d7f-497e-b160-b545075e2cba']);
    $operation = savedNativeProvisionIntent();
    expect($operation->input['node_id'])->toBe($this->gateway->id)
        ->and($operation->input['upcloud_token'])->toBe(['$secret' => 'upcloud.api', 'id' => $this->vm->id])
        ->and(app(TaskVmProvisionIntent::class)->input($operation))->not->toHaveKey('upcloud_token');
});

it('refuses incomplete allocation instead of selecting an image or fabricating readiness', function (string $field): void {
    $this->vm->update([$field => null]);
    expect(fn () => app(TaskVmProvisionIntent::class)->capture($this->vm->fresh()))->toThrow(ResourceOperationException::class);
})->with(['image_fingerprint', 'recipe_identity']);

it('refuses VM ownership drift while allowing its own newly reserved Node identity', function (string $field): void {
    $operation = savedNativeProvisionIntent();
    $this->vm->update([$field => $field === 'host_node_id' ? $this->gateway->id : ($field === 'wireguard_ip' ? '10.44.0.207' : 'changed')]);
    expect(fn () => app(TaskVmProvisionIntent::class)->owned($operation))->toThrow(ResourceOperationException::class);
})->with(['host_node_id', 'wireguard_ip', 'recipe_identity', 'image_fingerprint', 'name']);

it('binds the pre-reserved guest Node and refuses a moved guest identity', function (bool $matches): void {
    $operation = savedNativeProvisionIntent();
    if (! $matches) {
        $this->vm->fresh()->node->update(['name' => 'foreign-guest']);
    }
    if ($matches) {
        expect(app(TaskVmProvisionIntent::class)->owned($operation)->node_id)->toBe($operation->input['guest_node_id']);
    } else {
        expect(fn () => app(TaskVmProvisionIntent::class)->owned($operation))->toThrow(ResourceOperationException::class);
    }
})->with([true, false]);

it('projects native outcome once without running provider or guest work', function (string $status, TaskVmState $state): void {
    $operation = savedNativeProvisionIntent();
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:00:01.000Z',
        'error_code' => $status === 'failed' ? 'task_vm.provision_failed' : null,
        'result' => $status === 'succeeded' ? ['ready' => true] : []];
    $projection = app(TaskVmProvisionProjection::class);
    $projection->apply($operation, $data);
    expect($operation->fresh()->status)->toBe(OperationStatus::from($status))->and($this->vm->fresh()->state)->toBe($state);
    $before = $this->vm->fresh()->getRawOriginal();
    $projection->apply($operation->fresh(), $data);
    expect($this->vm->fresh()->getRawOriginal())->toBe($before);
    expect(fn () => $projection->apply($operation->fresh(), [...$data, 'status' => 'running', 'result' => []]))->toThrow(ResourceOperationException::class);
})->with([['succeeded', TaskVmState::Ready], ['failed', TaskVmState::Failed], ['cancelled', TaskVmState::Provisioning]]);

it('records native success without Gateway runtime revalidation', function (): void {
    $operation = savedNativeProvisionIntent();
    $this->vm->update(['state' => 'ready']);
    app(TaskVmProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:00:01.000Z', 'result' => ['ready' => true]]);
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)->and($this->vm->fresh()->state)->toBe(TaskVmState::Ready);
});

it('starts and deduplicates the native root without creating children or provider effects', function (): void {
    config(['swamp.run_token' => 'fixture.fake']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-vm-provision', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $socket);
    $operation = app(StartTaskVmProvisionAction::class)->execute($this->vm->id);
    expect($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676')
        ->and($operation->worker)->toBeNull()->and($operation->lock_key)->toBeNull()
        ->and(app(StartTaskVmProvisionAction::class)->execute($this->vm->id)->id)->toBe($operation->id);
});

it('refuses a competing VM root instead of creating another allocation', function (): void {
    $existing = Operation::query()->create(['kind' => 'task_vm.destroy', 'target_type' => 'task_vm', 'target_id' => $this->vm->id,
        'node_id' => $this->host->id, 'status' => 'queued', 'triggered_by' => 'fixture', 'input' => []]);
    expect(fn () => app(StartTaskVmProvisionAction::class)->execute($this->vm->id))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->sole()->id)->toBe($existing->id)->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning);
});

it('does not admit allocation after the VM has left provisioning', function (): void {
    $this->vm->update(['state' => 'destroyed']);
    expect(app(StartTaskVmProvisionAction::class)->execute($this->vm->id))->toBeNull()
        ->and(app(StartTaskVmProvisionAction::class)->execute(999999))->toBeNull()
        ->and(Operation::query()->count())->toBe(0);
});

it('records the root public peer key on the intent-selected guest before private hub reads', function (): void {
    $operation = savedNativeProvisionIntent();
    $publicKey = base64_encode(str_repeat('w', 32));
    app(TaskVmProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => ['node' => ['wireguard_public_key' => $publicKey, 'node_id' => $this->gateway->id]]]);
    expect($this->vm->fresh()->node->wireguard_public_key)->toBe($publicKey)
        ->and($this->gateway->fresh()->wireguard_public_key)->toBeNull()
        ->and(app(TaskVmProvisionIntent::class)->input($operation->fresh())['enrollment']['wireguard_public_key'])->toBe($publicKey)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning);
});

it('records native allocation metadata before first-visit bootstrap without promoting readiness', function (): void {
    $operation = savedNativeProvisionIntent();
    app(TaskVmProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => ['provider' => ['address' => '192.0.2.208', 'provider_id' => null, 'ssh_host_key' => null]]]);
    expect($this->vm->fresh()->address)->toBe('192.0.2.208')->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning);
});

it('records network enrollment without marking the VM runtime ready or granting global access', function (): void {
    $operation = savedNativeProvisionIntent();
    $guest = $this->vm->fresh()->node;
    $edges = DB::table('node_access')->count();
    app(TaskVmProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => ['node' => ['network_enrolled' => true]]]);
    expect($guest->fresh()->status)->toBe(LifecycleStatus::Active)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning)
        ->and($guest->roles()->where('role', RoleName::AppDev)->sole()->status)->toBe(LifecycleStatus::Provisioning)
        ->and(DB::table('node_access')->count())->toBe($edges);
});

it('exposes only public provision input to admitted participants without a global access edge', function (): void {
    $operation = savedNativeProvisionIntent();
    $projection = app(TaskVmProvisionProjection::class);
    $input = $projection->publicInput($operation, $operation->input['guest_node_id']);
    expect($input['guest_node_id'])->toBe($operation->input['guest_node_id'])
        ->and($input)->not->toHaveKeys(['installation', 'configuration', 'first_visit', 'upcloud_token']);
    expect(fn () => $projection->publicInput($operation, 999999))->toThrow(ResourceOperationException::class);
});

it('serves the bound public root to its placed Gateway and enrolled guest without changing root authority', function (string $placement): void {
    $operation = savedNativeProvisionIntent();
    $guest = $this->vm->fresh()->node;
    $guest->update(['status' => LifecycleStatus::Active]);
    $peer = $placement === 'gateway' ? $this->gateway : $guest;
    $this->withServerVariables(['REMOTE_ADDR' => $peer->wireguard_ip]);
    $path = '/api/v1/direct-operations/'.$operation->id;
    $run = $operation->state['swamp_run_id'];
    $edges = DB::table('node_access')->count();

    $this->getJson($path.'?run_id='.$run)->assertOk()
        ->assertJsonPath('data.node_id', $this->host->id)
        ->assertJsonPath('data.input.gateway_node_id', $this->gateway->id)
        ->assertJsonPath('data.input.guest_node_id', $guest->id)
        ->assertJsonMissingPath('data.input.configuration')
        ->assertJsonMissingPath('data.input.worker_credentials');
    $this->getJson($path.'?run_id=f5518d34-4395-4454-ab37-7f3cdcae1ad4')->assertConflict();
    $this->postJson($path.'/result', ['run_id' => $run, 'status' => 'running', 'started_at' => '2026-10-31T00:00:00.000Z', 'result' => []])->assertForbidden();
    expect(DB::table('node_access')->count())->toBe($edges)
        ->and($operation->fresh()->status)->toBe(OperationStatus::Queued);
})->with(['gateway', 'guest']);

it('ordinary allocation records the native parent instead of dispatching provision jobs', function (): void {
    TaskVmRecipeImages::ready($this->host->id);
    $group = Task::topLevel()->create(['project_id' => $this->vm->group->project_id, 'title' => 'New provision', 'brief' => 'Native provision', 'status' => 'reserved', 'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    config(['swamp.run_token' => 'fixture.fake']);
    $socket = new FakeRoutePublicationSocket;
    $teardownCouldEnter = null;
    $socket->reply = function (array $message) use (&$teardownCouldEnter): array {
        $probe = Cache::lock(ModelKeyIssuer::Lock, 30);
        $teardownCouldEnter = $probe->get();
        if ($teardownCouldEnter) {
            $probe->release();
        }

        return [['type' => 'event', 'id' => $message['id'], 'event' => [
            'kind' => 'started', 'workflowName' => 'operation-task-vm-provision', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    };
    app()->instance(WebSocketClient::class, $socket);
    $vm = app(AllocateTaskVmAction::class)->execute($group);
    $operation = Operation::query()->where('kind', 'task_vm.provision')->sole();
    expect($operation->target_id)->toBe($vm->id)
        ->and($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676')
        ->and($operation->input['guest_node_id'])->toBe($vm->fresh()->node_id)
        ->and(app(AllocateTaskVmAction::class)->execute($group)->id)->toBe($vm->id)
        ->and(Operation::query()->count())->toBe(1)
        ->and($teardownCouldEnter)->toBeFalse();
});

it('refused native admission rolls back ordinary allocation instead of stranding a VM', function (): void {
    TaskVmRecipeImages::ready($this->host->id);
    TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->host->id)], 'devClusterId' => null]);
    $group = Task::topLevel()->create(['project_id' => $this->vm->group->project_id, 'title' => 'New provision', 'brief' => 'Native provision', 'status' => 'reserved', 'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    expect(fn () => app(AllocateTaskVmAction::class)->execute($group))
        ->toThrow(TaskVmException::class);
    expect(TaskVm::forGroup($group))->toBeNull()->and(Operation::query()->count())->toBe(0);
});

it('captures placement for an owning Task root without starting another Operation or reserving enrollment', function (): void {
    TaskVmRecipeImages::ready($this->host->id);
    $group = Task::topLevel()->create(['project_id' => $this->vm->group->project_id, 'title' => 'Same-root provision', 'brief' => 'Native provision', 'status' => 'reserved', 'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $placement = app(AllocateTaskVmAction::class);
    $vm = $placement->capture($group);
    expect($vm->state)->toBe(TaskVmState::Provisioning)
        ->and($vm->node_id)->toBeNull()
        ->and($vm->recipe_identity)->not->toBeNull()
        ->and($vm->image_fingerprint)->not->toBeNull()
        ->and($placement->capture($group)->id)->toBe($vm->id)
        ->and(Operation::query()->count())->toBe(0)
        ->and(Node::query()->count())->toBe(4);
});
