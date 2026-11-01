<?php

declare(strict_types=1);

use App\Actions\Tasks\StartTaskProvisionAction;
use App\Data\Tasks\StartTaskAnalysisData;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAnalysisIntent;
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
    $model->shouldReceive('privateMaterial')->andReturn(['management_key' => 'fixture-management', 'anchor_key' => 'fixture-anchor']);
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
    config(['orbit.home' => $home, 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem']);
    foreach ([$this->host, $this->gateway, $vpn] as $node) {
        $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']);
    }
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
    $this->socket->reply = fn (array $message): array => ($message['type'] ?? null) === 'vault.put' ? [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]] : [['type' => 'event', 'id' => $message['id'], 'event' => [
        'kind' => 'started', 'workflowName' => $message['payload']['workflowIdOrName'], 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]];
    app()->instance(WebSocketClient::class, $this->socket);
    app()->instance(TaskVmProvider::class, Mockery::mock(TaskVmProvider::class));
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));

});

function taskProvisionVmReceipt(Operation $operation): array
{
    $key = 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG';

    return ['task_vm_id' => $operation->input['task_vm_id'], 'guest_node_id' => $operation->input['guest_node_id'], 'ready' => true,
        'provider' => ['provider_id' => null, 'address' => '10.251.77.2', 'image_fingerprint' => $operation->input['image_fingerprint'], 'running' => true, 'settled' => true],
        'enrollment' => ['ready' => true, 'node' => ['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $key,
            'ssh_host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($key), true)), '='),
            'wireguard_public_key' => base64_encode(str_repeat('w', 32)), 'architecture' => 'x86_64', 'address' => '10.251.77.2', 'observer_digest' => str_repeat('e', 64)],
            'process' => ['process_id' => $operation->input['enrollment']['runtime']['process']['process_id'], 'status' => 'active']]];
}

it('admits one Tasks root with canonical placement, enrollment and checkout intent', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $envelope = app(TaskProvisionProjection::class)->input($operation);
    $input = $envelope['intent'];
    expect($operation->kind)->toBe('tasks.provision')->and($operation->target_id)->toBe($this->group->id)
        ->and($operation->node_id)->toBe($this->host->id)->and(Operation::query()->count())->toBe(1)
        ->and($operation->state['swamp_run_id'])->toBe('a075eb23-7ac4-4487-80d1-57a038a92676')
        ->and($input['instance_id'])->toBe($this->group->fresh()->taskable_id)
        ->and($input['guest_node_id'])->toBe($this->vm->fresh()->node_id)
        ->and($input['workspace']['checkout_path'])->toBe('/home/orbit/apps/native-provision/workspace')
        ->and($input['workspace']['repository'])->toBe($this->project->repository_url)
        ->and($input['workspace']['branch'])->toBe('task-'.$this->group->id)
        ->and($input['workspace']['skip_profile'])->toBeTrue()
        ->and($input['ssh_identity']['control_node_id'])->toBe(209)
        ->and($input)->not->toHaveKeys(['private_bindings', 'git_read', 'installation', 'model_key', 'execution_secrets'])
        ->and($envelope['execution_secrets']['pi_token'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$this->vm->id])
        ->and(json_encode($envelope, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('d', 64))->not->toContain('fixture-management')
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
        ->and(Instance::query()->count())->toBe(1)->and(collect($this->socket->sent)->where('type', 'workflow.run')->count())->toBe(1);
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

it('ignores intermediate VM claims until the original native root is terminal', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $projection = app(TaskProvisionProjection::class);
    $projection->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => taskProvisionVmReceipt($operation)]);
    expect($this->vm->fresh()->address)->toBeNull()
        ->and($this->vm->fresh()->node->wireguard_public_key)->toBeNull()
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning)
        ->and($this->group->fresh()->status->value)->toBe('reserved');
});

it('records VM and workspace readiness together and repeats the result without another effect', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $source = ['branch' => 'task-'.$this->group->id, 'commit' => str_repeat('a', 40), 'php_version' => null, 'laravel' => false];
    $data = ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:01:00.000Z', 'error_code' => null,
        'result' => ['source' => $source, 'instance_id' => $operation->input['instance_id'], 'starting_commit' => $source['commit'], 'analysis_base' => ['base' => $source['commit'], 'instance_id' => $operation->input['instance_id'], 'repository' => $this->project->repository_url, 'branch' => $source['branch']],
            ...taskProvisionVmReceipt($operation)]];
    $projection = app(TaskProvisionProjection::class);
    $projection->apply($operation, $data);
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Ready)
        ->and($this->group->fresh()->status->value)->toBe('running')
        ->and($this->group->fresh()->taskable->starting_commit)->toBe($source['commit'])
        ->and($this->group->fresh()->taskable->status->value)->toBe('source_resolved');
    $projection->apply($operation->fresh(), $data);
    expect(Instance::query()->count())->toBe(1)->and(Operation::query()->count())->toBe(1);
    $child = Task::query()->create(['parent_id' => $this->group->id, 'position' => 1, 'title' => 'Analyze', 'brief' => 'Analyze source.', 'status' => 'running']);
    $analysis = app(TaskAnalysisIntent::class)->start($this->group->fresh(), new StartTaskAnalysisData(
        subtask: $child->id, plan: [], plan_sha256: str_repeat('b', 64), base: $source['commit'], head: $source['commit'], tree: str_repeat('c', 40)), 'fixture', null);
    expect($analysis->kind)->toBe('tasks.analyze')->and($analysis->input['repository'])->toBe($this->project->repository_url)
        ->and($analysis->input['base'])->toBe($source['commit']);
});

it('ends a failed, interrupted or cancelled native provision without claiming VM removal', function (string $status): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    app(TaskProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => $status,
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:01:00.000Z',
        'error_code' => in_array($status, ['failed', 'interrupted'], true) ? 'tasks.provision_failed' : null, 'result' => []]);
    expect($this->group->fresh()->status->value)->toBe($status === 'interrupted' ? 'failed' : $status)
        ->and($this->group->fresh()->reserved_at)->toBeNull()
        ->and($this->vm->fresh()->state)->not->toBe(TaskVmState::Destroyed);
})->with(['failed', 'interrupted', 'cancelled']);

it('retains confirmed VM readiness when terminal source preparation fails', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    app(TaskProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'failed',
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:01:00.000Z',
        'error_code' => 'tasks.provision_failed', 'result' => [...taskProvisionVmReceipt($operation), 'failed_step' => 'workspace']]);
    expect($this->vm->fresh()->state)->toBe(TaskVmState::Ready)
        ->and($this->vm->fresh()->node->status)->toBe(LifecycleStatus::Active)
        ->and($this->group->fresh()->status->value)->toBe('failed')
        ->and($this->group->fresh()->taskable->failed_step)->toBe('workspace')
        ->and($this->group->fresh()->taskable->first_setup_pending)->toBeTrue();
});

it('refuses a readiness receipt for another VM or admitted image', function (string $field): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $result = taskProvisionVmReceipt($operation);
    if ($field === 'image_fingerprint') {
        $result['provider'][$field] = str_repeat('f', 64);
    } else {
        $result[$field]++;
    }
    expect(fn () => app(TaskProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'failed',
        'error_code' => 'tasks.provision_failed', 'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => $result]))->toThrow(ResourceOperationException::class);
    expect($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning)
        ->and($this->group->fresh()->status->value)->toBe('reserved');
})->with(['task_vm_id', 'guest_node_id', 'image_fingerprint']);

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
        ->and(collect($this->socket->sent)->where('type', 'workflow.run')->count())->toBe(1);

});

it('rejects native success without confirmed VM readiness', function (): void {
    $operation = app(StartTaskProvisionAction::class)->execute($this->group, 'fixture', null);
    $source = ['branch' => 'task-'.$this->group->id, 'commit' => str_repeat('a', 40), 'php_version' => null, 'laravel' => false];
    expect(fn () => app(TaskProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'succeeded',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['source' => $source]]))
        ->toThrow(ResourceOperationException::class);
    expect($this->group->fresh()->status->value)->toBe('reserved')->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning);
});

it('refuses the canonical proof Project before placement when its sandbox credential is absent', function (): void {
    $proof = Project::query()->forceCreate(['id' => 62, 'name' => 'Proof', 'type' => 'monorepo', 'slug' => 'swamp-proof',
        'repository_url' => 'https://github.com/example/proof.git', 'default_branch' => 'main', 'task_workspace_routed' => false]);
    $this->group->update(['project_id' => $proof->id]);
    config(['swamp.proof_club_api_key' => '']);

    expect(fn () => app(StartTaskProvisionAction::class)->execute($this->group->fresh(), 'fixture', null))
        ->toThrow(ResourceOperationException::class, 'Gateway-held sandbox club credential');
    expect($this->vm->fresh()->node_id)->toBeNull()->and(Instance::query()->count())->toBe(0)
        ->and(Operation::query()->count())->toBe(0)->and($this->socket->sent)->toBe([])
        ->and($this->group->fresh()->status->value)->toBe('todo');
});

it('copies the club credential only for the exact canonical proof Project and exposes only its descriptor', function (int $id, string $slug, bool $expected): void {
    $proof = Project::query()->forceCreate(['id' => $id, 'name' => 'Proof', 'type' => 'monorepo', 'slug' => $slug,
        'repository_url' => 'https://github.com/example/proof.git', 'default_branch' => 'main', 'task_workspace_routed' => false]);
    $this->group->update(['project_id' => $proof->id]);
    $credential = 'sandbox-test-'.bin2hex(random_bytes(24));
    config(['swamp.proof_club_api_key' => $credential]);

    $operation = app(StartTaskProvisionAction::class)->execute($this->group->fresh(), 'fixture', null);
    $envelope = app(TaskProvisionProjection::class)->input($operation);
    $copies = collect($this->socket->sent)->filter(static fn (array $message): bool => ($message['type'] ?? null) === 'vault.put'
        && ($message['payload']['key'] ?? null) === 'swamp-proof-club-api-key');

    expect(isset($envelope['execution_secrets']['proof_club']))->toBe($expected)
        ->and($copies)->toHaveCount($expected ? 1 : 0)
        ->and(isset($operation->input['proof_club_required']))->toBe($expected)
        ->and(json_encode($operation->fresh()->toArray(), JSON_THROW_ON_ERROR))->not->toContain($credential)
        ->and(json_encode($envelope, JSON_THROW_ON_ERROR))->not->toContain($credential);
    if ($expected) {
        expect($envelope['execution_secrets']['proof_club'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'swamp-proof-club-api-key']);
    }
})->with([[62, 'swamp-proof', true], [62, 'another-project', false], [63, 'swamp-proof', false]]);
