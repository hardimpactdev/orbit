<?php

declare(strict_types=1);

use App\Actions\Tasks\StartTaskProvisionAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Instances\InstanceLifecycleGitSecret;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskWorkspaceLeaseRecord;
use Illuminate\Support\Facades\File;
use Tests\Support\FakeRoutePublicationSocket;
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
