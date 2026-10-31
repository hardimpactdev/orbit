<?php

declare(strict_types=1);

use App\Actions\Tasks\StartTaskProvisionAction;
use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceLifecycleGitSecret;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\LifecycleStep;
use App\Domain\Projects\ProjectLifecycleStepStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\Tasks\TaskProvisionWorkflowInputs;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmRecipeImages;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $this->host = Node::query()->forceCreate(['id' => 91, 'name' => 'provision-host', 'user' => 'nckrtl', 'platform' => 'linux', 'architecture' => 'x86_64', 'status' => LifecycleStatus::Active, 'wireguard_ip' => '10.44.0.13', 'public_ssh_host' => '192.0.2.13']);
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
    $project = Project::query()->create(['name' => 'Provision', 'type' => 'monorepo', 'task_workspace_routed' => false, 'slug' => 'native-provision', 'repository_url' => 'https://github.com/example/provision.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Provision', 'brief' => 'Native provision', 'status' => 'todo', 'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $this->group = $group;
    $this->project = $project;
    $this->vm = TaskVm::query()->create(['name' => 'pending', 'provider' => 'incus', 'state' => 'provisioning', 'host_node_id' => $this->host->id,
        'group_id' => $group->id, 'pi_token' => str_repeat('d', 64), 'wireguard_ip' => '10.44.0.208', 'image_fingerprint' => str_repeat('a', 64), 'recipe_identity' => 'sha256:'.str_repeat('b', 64)]);
    $this->vm->update(['name' => 'tvm-'.$this->vm->id]);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 '.base64_encode(str_repeat('a', 32)));
    app()->instance(SshKeyProvider::class, $keys);
    app(TaskExtensionState::class)->enable();
    config(['swamp.run_token' => 'fixture.fake']);
    $this->socket = new FakeRoutePublicationSocket;
    $this->socket->reply = fn (array $message): array => [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-task-provision', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $this->socket);
    app()->instance(TaskVmProvider::class, Mockery::mock(TaskVmProvider::class));
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));

});

it('admits one Tasks root with canonical placement, enrollment and checkout intent', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $input = app(TaskProvisionProjection::class)->input($operation);
    expect($operation->kind)->toBe('tasks.provision')->and($operation->target_id)->toBe($this->group->id)
        ->and($operation->node_id)->toBe($this->host->id)->and(Operation::query()->count())->toBe(1)
        ->and($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676')
        ->and($input['instance_id'])->toBe($this->group->fresh()->taskable_id)
        ->and($input['direct_provisioning']['node_id'])->toBe($this->vm->fresh()->node_id)
        ->and($input['direct_provisioning']['checkout'])->toBe('/home/orbit/apps/native-provision/workspace')
        ->and($input['direct_provisioning']['source']['repository'])->toBe($this->project->repository_url)
        ->and($input['direct_provisioning']['resolve']['branch_override'])->toBe('task-'.$this->group->id)
        ->and($input['private_bindings']['git_read']['read_node_ids'])->toBe([$this->vm->fresh()->node_id])
        ->and($input)->not->toHaveKey('git_read')->not->toHaveKey('installation')->not->toHaveKey('model_key')
        ->and($this->group->fresh()->status->value)->toBe('reserved')
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning)
        ->and(TaskWorkspaceLeaseRecord::query()->count())->toBe(0)
        ->and(Instance::query()->sole()->status->value)->toBe('reserved');
});

it('replays before group validation and starts no second native run', function (): void {
    $key = app(OperationIdempotency::class);
    $key->kind = 'tasks.provision';
    $key->key = 'native-task-provision';
    $key->fingerprint = str_repeat('a', 64);
    $first = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $this->group->update(['status' => 'completed']);
    $second = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    expect($second->id)->toBe($first->id)->and(Operation::query()->count())->toBe(1)
        ->and(Instance::query()->count())->toBe(1)->and(count($this->socket->sent))->toBe(1);
});

it('refuses unsupported publication before it captures any guest or workspace', function (): void {
    $this->project->update(['task_workspace_routed' => true]);
    expect(fn () => app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null))
        ->toThrow(ResourceOperationException::class, 'Task workspace publication');
    expect($this->vm->fresh()->node_id)->toBeNull()->and(Instance::query()->count())->toBe(0)
        ->and(Operation::query()->count())->toBe(0)->and($this->group->fresh()->status->value)->toBe('todo');
});

it('refuses a non-todo group without placement or a native run', function (string $status): void {
    $this->group->update(['status' => $status]);
    expect(fn () => app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe(0)->and(Instance::query()->count())->toBe(0)->and($this->vm->fresh()->node_id)->toBeNull();
})->with(['backlog', 'reserved', 'running', 'completed', 'cancelled']);

it('records public VM checkpoints without advancing the group or touching a provider', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $projection = app(TaskProvisionProjection::class);
    $projection->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => [
            'provider' => ['address' => '10.251.77.2', 'provider_id' => null],
            'node' => ['wireguard_public_key' => base64_encode(str_repeat('w', 32))],
        ]]);
    expect($this->vm->fresh()->address)->toBe('10.251.77.2')
        ->and($this->vm->fresh()->node->wireguard_public_key)->toBe(base64_encode(str_repeat('w', 32)))
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning)
        ->and($this->group->fresh()->status->value)->toBe('reserved');
});

it('records VM and workspace readiness together and repeats the result without another effect', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $source = ['branch' => 'task-'.$this->group->id, 'commit' => str_repeat('a', 40), 'php_version' => null, 'laravel' => false];
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:01:00.000Z', 'error_code' => null,
        'result' => ['source' => $source, 'instance_id' => $operation->input['instance_id'], 'starting_commit' => $source['commit'], 'analysis_base' => null,
            'enrollment' => ['ready' => true, 'node' => ['address' => '10.251.77.2'], 'process' => ['status' => 'active']]]];
    $projection = app(TaskProvisionProjection::class);
    $projection->apply($operation, $data);
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Ready)
        ->and($this->group->fresh()->status->value)->toBe('running')
        ->and($this->group->fresh()->taskable->starting_commit)->toBe($source['commit'])
        ->and($this->group->fresh()->taskable->status->value)->toBe('source_resolved');
    $projection->apply($operation->fresh(), $data);
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
});

it('ends a failed or cancelled native provision without claiming VM removal', function (string $status): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    app(TaskProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:01:00.000Z',
        'error_code' => $status === 'failed' ? 'tasks.provision_failed' : null, 'result' => []]);
    expect($this->group->fresh()->status->value)->toBe($status)
        ->and($this->group->fresh()->reserved_at)->toBeNull()
        ->and($this->vm->fresh()->state)->not->toBe(TaskVmState::Destroyed);
})->with(['failed', 'cancelled']);

it('binds source credentials to this group and guest rather than another workspace', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $source = app(InstanceLifecycleGitSecret::class);
    expect($source->ownerId($operation))->toBe($this->project->id);
    Instance::query()->whereKey($operation->input['instance_id'])->update(['task_workspace_owner_id' => null]);
    expect($source->ownerId($operation))->toBeNull();
});

it('retains confirmed VM readiness when workspace preparation fails later', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $projection = app(TaskProvisionProjection::class);
    $projection->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => ['phase' => 'vm-ready',
            'enrollment' => ['ready' => true, 'node' => ['address' => '10.251.77.2'], 'process' => ['status' => 'active']]]]);
    expect($this->vm->fresh()->state)->toBe(TaskVmState::Ready)
        ->and($this->group->fresh()->status->value)->toBe('reserved')
        ->and($projection->input($operation->fresh())['eligible'])->toBeTrue();
    $projection->apply($operation->fresh(), ['run_id' => $operation->state['swamp_run_id'], 'status' => 'failed',
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:01:00.000Z',
        'error_code' => 'tasks.provision_failed', 'result' => ['failed_step' => 'workspace']]);
    expect($this->vm->fresh()->state)->toBe(TaskVmState::Ready)->and($this->group->fresh()->status->value)->toBe('failed');
});

it('refuses missing current recipe images without admission and permits the same key once an image is ready', function (): void {
    $this->vm->delete();
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    $beforeNodes = Node::query()->count();
    $before = $this->group->fresh()->getRawOriginal();
    $key = 'native-provision-image-not-ready';

    $response = $this->withHeader('Idempotency-Key', $key)
        ->postJson('/api/v1/task-groups/'.$this->group->id.'/provision', [])
        ->assertConflict()->assertJsonPath('error.code', 'tasks.capacity_unavailable')
        ->assertJsonPath('error.details.reason', 'capacity');

    expect($response->json('error.message'))->toContain('waiting for recipe', 'images on provision-host')
        ->and($response->headers->get('X-Orbit-Request-Id'))->not->toBeEmpty()
        ->and($this->group->fresh()->getRawOriginal())->toBe($before)
        ->and(TaskVm::query()->where('group_id', $this->group->id)->exists())->toBeFalse()
        ->and(Instance::query()->where('task_workspace_owner_id', $this->group->id)->exists())->toBeFalse()
        ->and(Operation::query()->where('target_type', 'task_group')->where('target_id', $this->group->id)->exists())->toBeFalse()
        ->and(Node::query()->count())->toBe($beforeNodes)
        ->and($this->socket->sent)->toBe([]);

    TaskVmRecipeImages::ready($this->host->id);
    $this->postJson('/api/v1/task-groups/'.$this->group->id.'/provision', [])->assertAccepted();
    expect(Operation::query()->where('kind', 'tasks.provision')->sole()->idempotency_key)->toBe($key)
        ->and(TaskVm::query()->where('group_id', $this->group->id)->count())->toBe(1)
        ->and(Instance::query()->where('task_workspace_owner_id', $this->group->id)->count())->toBe(1)
        ->and(count($this->socket->sent))->toBe(1);

});

/** @return array{0: Instance, 1: array<string, mixed>} */
function taskCommandProvisionAllocation(Task $group, TaskVm $vm): array
{
    return app(TaskVmEnrollmentIntent::class)->admit(fn (): array => DB::transaction(function () use ($group, $vm): array {
        app(AllocateTaskVmAction::class)->capture($group);
        $vm->refresh();
        $allocation = app(TaskVmProvisionIntent::class)->capture($vm);
        $workspace = Instance::query()->create([
            'project_id' => $group->project_id, 'node_id' => $vm->node_id, 'name' => 'task-'.$group->id,
            'source_layout' => 'checkout', 'source_prepare_id' => 'task-command-fixture',
            'checkout_path' => '/home/orbit/apps/native-provision/workspace', 'root' => null,
            'branch_override' => 'task-'.$group->id, 'task_workspace_routed' => false,
            'task_workspace_owner_id' => $group->id, 'status' => 'reserved', 'first_setup_pending' => true,
        ]);

        return [$workspace, $allocation];
    }));
}

it('composes Tasks command inputs with full frozen setup commands and canonical VM copies without another admission', function (): void {
    config(['swamp.run_token' => 'synthetic-start-token', 'swamp.ca_path' => '/public/root.pem', 'swamp.vault_server' => 'wss://tasks-command.test']);
    app(ModelKeyIssuer::class)->shouldReceive('privateMaterial')->once()->andReturn([
        'management_key' => 'synthetic-management-key', 'anchor_key' => str_repeat('2', 64),
    ]);
    $runner = Mockery::mock(ProcessRunner::class);
    $runner->shouldReceive('run')->times(4)->andReturn(new CommandResult(0, '{}', '', 1, false));
    app()->instance(ProcessRunner::class, $runner);
    $steps = app(ProjectLifecycleStepStore::class);
    $steps->create($this->project, LifecyclePhase::Setup, new LifecycleStep('first', 'printf first', 30), null, null);
    $steps->create($this->project, LifecyclePhase::Setup, new LifecycleStep('second', 'printf second', 30), null, null);
    [$workspace, $allocation] = taskCommandProvisionAllocation($this->group, $this->vm);

    $inputs = app(TaskProvisionWorkflowInputs::class)->capture($this->group, $workspace, $this->vm, $allocation);
    $steps->update($this->project, LifecyclePhase::Setup, 'first', 'printf changed', null, null, null, true, false);

    expect($inputs['intent'])->toMatchArray(['group_id' => $this->group->id, 'task_vm_id' => $this->vm->id,
        'node_id' => $this->host->id, 'guest_node_id' => $this->vm->node_id, 'instance_id' => $workspace->id,
        'repository' => $this->project->repository_url, 'repository_identity' => 'github.com/example/provision']);
    expect($inputs['intent']['workspace']['setup']['steps'])->toBe([
        ['name' => 'first', 'command' => 'printf first', 'timeout_seconds' => 30, 'command_digest' => hash('sha256', 'printf first')],
        ['name' => 'second', 'command' => 'printf second', 'timeout_seconds' => 30, 'command_digest' => hash('sha256', 'printf second')],
    ]);
    expect($inputs['execution_secrets']['pi_token'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$this->vm->id]);
    expect(array_keys($inputs['execution_secrets']))->toBe(['model_key', 'model_router_management', 'model_router_anchor', 'pi_token']);
    expect(json_encode($inputs, JSON_THROW_ON_ERROR))->not->toContain($this->vm->model_key, $this->vm->pi_token,
        'synthetic-management-key', str_repeat('2', 64), '$secret', 'private_bindings', 'installation', 'git_read');
    expect(Operation::query()->count())->toBe(0)->and(TaskVm::query()->count())->toBe(1)->and(Instance::query()->count())->toBe(1);
    expect($this->socket->sent)->toBe([]);
});

it('refuses mismatched Tasks workspace inputs before any execution-copy write', function (string $field, mixed $value): void {
    [$workspace, $allocation] = taskCommandProvisionAllocation($this->group, $this->vm);
    $workspace->setAttribute($field, $value);
    app()->instance(ProcessRunner::class, Mockery::mock(ProcessRunner::class));

    expect(fn () => app(TaskProvisionWorkflowInputs::class)->capture($this->group, $workspace, $this->vm, $allocation))
        ->toThrow(ResourceOperationException::class, 'same admitted group');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
})->with([
    'foreign group' => ['task_workspace_owner_id', 999999],
    'foreign node' => ['node_id', 999999],
    'foreign project' => ['project_id', 999999],
    'worktree' => ['source_layout', 'worktree'],
    'other branch' => ['branch_override', 'other'],
    'escaped checkout' => ['checkout_path', '/home/orbit/apps/../workspace'],
    'missing prepare identity' => ['source_prepare_id', null],
]);

it('refuses a secret-bearing setup command before synchronizing any VM execution copy', function (): void {
    [$workspace, $allocation] = taskCommandProvisionAllocation($this->group, $this->vm);
    app(ProjectLifecycleStepStore::class)->create($this->project, LifecyclePhase::Setup,
        new LifecycleStep('unsafe', 'printf '.$this->vm->pi_token, 30), null, null);
    app()->instance(ProcessRunner::class, Mockery::mock(ProcessRunner::class));

    expect(fn () => app(TaskProvisionWorkflowInputs::class)->capture($this->group, $workspace, $this->vm, $allocation))
        ->toThrow(ResourceOperationException::class, 'no secret values');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
});

it('refuses foreign or proxy placements in the Tasks command envelope before copying secrets', function (string $field, int $value): void {
    [$workspace, $allocation] = taskCommandProvisionAllocation($this->group, $this->vm);
    $allocation[$field] = $value;
    app()->instance(ProcessRunner::class, Mockery::mock(ProcessRunner::class));

    expect(fn () => app(TaskProvisionWorkflowInputs::class)->capture($this->group, $workspace, $this->vm, $allocation))
        ->toThrow(ResourceOperationException::class, 'same admitted group');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
})->with([
    'foreign allocation' => ['task_vm_id', 999999],
    'foreign guest' => ['guest_node_id', 999999],
    'vpn proxy' => ['node_id', 1],
    'usexit proxy' => ['node_id', 18],
]);

it('refuses unsafe public source intent before copying any VM secret', function (string $field, mixed $value): void {
    [$workspace, $allocation] = taskCommandProvisionAllocation($this->group, $this->vm);
    $this->project->setAttribute($field, $value);
    $this->group->setRelation('project', $this->project);
    app()->instance(ProcessRunner::class, Mockery::mock(ProcessRunner::class));

    expect(fn () => app(TaskProvisionWorkflowInputs::class)->capture($this->group, $workspace, $this->vm, $allocation))
        ->toThrow(ResourceOperationException::class, 'same admitted group');
    expect(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([]);
})->with([
    'repository credentials' => ['repository_url', 'https://token@github.com/example/provision.git'],
    'option branch' => ['default_branch', '--other'],
    'missing branch' => ['default_branch', null],
]);
