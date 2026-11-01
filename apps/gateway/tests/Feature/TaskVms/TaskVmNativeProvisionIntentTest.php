<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\StartTaskVmProvisionAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmProvisionIntent;
use App\Domain\TaskVms\TaskVmProvisionProjection;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\TaskVmUpCloud;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Infrastructure\TaskVms\UpCloudApi;
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
    config(['orbit.home' => $home, 'swamp.address' => '192.0.2.209', 'swamp.ca_path' => '/public/ca.pem', 'swamp.run_token' => 'fixture.fake']);
    foreach ([$this->host, $this->gateway, $vpn] as $node) {
        $node->update(['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']);
    }
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]];
    app()->instance(WebSocketClient::class, $socket);
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

function savedNativeProvisionIntent(): Operation
{
    $input = app(TaskVmProvisionIntent::class)->capture(test()->vm);

    return Operation::query()->create(['kind' => 'task_vm.provision', 'target_type' => 'task_vm', 'target_id' => test()->vm->id,
        'node_id' => $input['node_id'], 'status' => 'queued', 'triggered_by' => 'fixture', 'input' => $input,
        'state' => ['swamp_run_id' => 'a075eb23-7ac4-4487-80d1-57a038a92676']]);
}

function nativeProvisionVaultReply(array $message): array
{
    return [['type' => 'vault.put', 'id' => $message['id'], 'payload' => ['data' => ['vaultName' => $message['payload']['vaultName'], 'secretKey' => $message['payload']['key'], 'vaultType' => 'local_encryption']]]];
}

function nativeProvisionReceipt(Operation $operation): array
{
    $key = 'AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG';

    return ['task_vm_id' => $operation->target_id, 'guest_node_id' => $operation->input['guest_node_id'], 'ready' => true,
        'provider' => ['provider_id' => null, 'address' => '192.0.2.208', 'image_fingerprint' => $operation->input['image_fingerprint'], 'running' => true, 'settled' => true],
        'enrollment' => ['ready' => true, 'node' => ['ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $key,
            'ssh_host_fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($key), true)), '='),
            'wireguard_public_key' => base64_encode(str_repeat('w', 32)), 'architecture' => 'x86_64', 'address' => '192.0.2.208', 'observer_digest' => str_repeat('e', 64)],
            'process' => ['process_id' => $operation->input['enrollment']['runtime']['process']['process_id'], 'status' => 'active']]];
}

it('freezes full public intent and descriptor-only copies without provider effects', function (): void {
    $operation = savedNativeProvisionIntent();
    $envelope = app(TaskVmProvisionIntent::class)->input($operation);
    $input = $envelope['intent'];
    expect($input['node_id'])->toBe($this->host->id)->and($input['guest_node_id'])->toBe($this->vm->fresh()->node_id)
        ->and($input['create']['image'])->toBe($this->vm->image_fingerprint)
        ->and($input['create']['expected_fingerprint'])->toBe($this->vm->image_fingerprint)
        ->and($input['bootstrap']['public_key'])->toBe(app(SwampSshExecutionInput::class)->identity()['public_key'])
        ->and($input['host_ssh_execution']['control_node_id'])->toBe(209)
        ->and($input['runtime']['process']['unit_name'])->toStartWith('orbit-process-')->toEndWith('-pi-server.service')
        ->and($input['network_input']['peer']['address'])->toStartWith($this->vm->wireguard_ip)
        ->and($input['hub_input']['context_node_id'])->toBe($input['guest_node_id'])
        ->and($input['vm']['name'])->toBe($this->vm->name)->and($input['eligible'])->toBeTrue()
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning)
        ->and($envelope['execution_secrets']['pi_token'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'task-vm-pi-token-'.$this->vm->id])
        ->and($operation->input)->not->toHaveKeys(['private_bindings', 'participant_node_ids', 'worker_credentials'])
        ->and(json_encode($envelope, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('d', 64))->not->toContain($this->vm->fresh()->model_key);
});

function configureNativeUpCloudProvision(): void
{
    $vm = test()->vm;
    $vm->update(['provider' => 'upcloud', 'host_node_id' => null, 'image_fingerprint' => '22b4a02c-81e6-4b01-a1ec-2a825933c0ba']);
    $cloud = new TaskVmUpCloud(true, [$vm->group->project_id], '/unused/token', 2, 'fi-hel1', 'test-plan', 20, $vm->image_fingerprint);
    TaskVmSettingsBuilder::bind(['upcloud' => $cloud, 'devClusterId' => null, 'piArtifactPath' => '/private/pi', 'piArtifactSha256' => str_repeat('c', 64), 'piModels' => ['test-model']]);
    app()->instance(UpCloudApi::class, new UpCloudApi(app(TaskVmSettings::class), 'PRIVATE-UPCLOUD-FIXTURE'));
}

it('captures Gateway-owned UpCloud bootstrap only through the shared vault writer', function (): void {
    configureNativeUpCloudProvision();
    $public = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG';
    $this->vm->update(['provider_bootstrap' => ['private' => 'PRIVATE-BOOTSTRAP-FIXTURE', 'public' => $public], 'ssh_host_key' => $public]);
    app()->instance(UpCloudApi::class, new UpCloudApi(app(TaskVmSettings::class), 'PRIVATE-UPCLOUD-FIXTURE'));
    $input = app(TaskVmProvisionIntent::class)->capture($this->vm->fresh());
    expect($input['node_id'])->toBe($this->gateway->id)->and($input['host_node_id'])->toBeNull()
        ->and($input['bootstrap_host_key'])->toBe($public)
        ->and($input['execution_secrets']['bootstrap'])->toBe(['vault' => 'orbit-control-inputs', 'key' => 'task-vm-provider-bootstrap-'.$this->vm->id])
        ->and($this->vm->fresh()->provider_bootstrap['private'])->toBe('PRIVATE-BOOTSTRAP-FIXTURE')
        ->and($this->vm->fresh()->getRawOriginal('provider_bootstrap'))->not->toContain('PRIVATE-BOOTSTRAP-FIXTURE')
        ->and(json_encode($input))->not->toContain('PRIVATE-BOOTSTRAP-FIXTURE')->not->toContain('PRIVATE-UPCLOUD-FIXTURE');
    $again = app(TaskVmProvisionIntent::class)->capture($this->vm->fresh());
    expect($again['bootstrap_host_key'])->toBe($public)->and($again['execution_secrets']['bootstrap'])->toBe($input['execution_secrets']['bootstrap']);
});

it('generates and retains one encrypted bootstrap pair for a new UpCloud VM', function (): void {
    configureNativeUpCloudProvision();
    $first = app(TaskVmProvisionIntent::class)->capture($this->vm->fresh());
    $pair = $this->vm->fresh()->provider_bootstrap;
    expect($pair['private'])->toContain('BEGIN OPENSSH PRIVATE KEY')
        ->and($pair['public'])->toBe($first['bootstrap_host_key'])
        ->and(json_encode($first))->not->toContain($pair['private']);
    app(TaskVmProvisionIntent::class)->capture($this->vm->fresh());
    expect($this->vm->fresh()->provider_bootstrap)->toBe($pair);
});

it('does not admit an UpCloud operation when its bootstrap vault copy is refused', function (): void {
    configureNativeUpCloudProvision();
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static function (array $message): array {
        return $message['payload']['key'] === 'task-vm-provider-bootstrap-'.test()->vm->id
            ? [['type' => 'error', 'id' => $message['id'], 'error' => 'denied']]
            : nativeProvisionVaultReply($message);
    };
    app()->instance(WebSocketClient::class, $socket);
    expect(fn () => app(StartTaskVmProvisionAction::class)->execute($this->vm->id))->toThrow(ResourceOperationException::class);
    expect(Operation::query()->where('kind', 'task_vm.provision')->count())->toBe(0)
        ->and($this->vm->fresh()->provider_bootstrap)->toBeNull();
});

it('refuses UpCloud bootstrap drift against the stored host pin', function (): void {
    configureNativeUpCloudProvision();
    app(TaskVmProvisionIntent::class)->capture($this->vm->fresh());
    $this->vm->update(['ssh_host_key' => 'ssh-ed25519 changed']);
    expect(fn () => app(TaskVmProvisionIntent::class)->capture($this->vm->fresh()))->toThrow(ResourceOperationException::class);
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
        'result' => $status === 'succeeded' ? nativeProvisionReceipt($operation) : []];
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
        'started_at' => '2026-10-31T00:00:00.000Z', 'finished_at' => '2026-10-31T00:00:01.000Z', 'result' => nativeProvisionReceipt($operation)]);
    expect($operation->fresh()->status)->toBe(OperationStatus::Succeeded)->and($this->vm->fresh()->state)->toBe(TaskVmState::Ready);
});

it('starts and deduplicates the native root without creating children or provider effects', function (): void {
    config(['swamp.run_token' => 'fixture.fake']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = fn (array $message): array => ($message['type'] ?? null) === 'vault.put' ? nativeProvisionVaultReply($message) : [['type' => 'event', 'id' => $message['id'], 'event' => [
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

it('does not project peer keys from intermediate progress', function (): void {
    $operation = savedNativeProvisionIntent();
    $publicKey = base64_encode(str_repeat('w', 32));
    app(TaskVmProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => ['node' => ['wireguard_public_key' => $publicKey, 'node_id' => $this->gateway->id]]]);
    expect($this->vm->fresh()->node->wireguard_public_key)->toBeNull()
        ->and($this->gateway->fresh()->wireguard_public_key)->toBeNull()
        ->and($operation->fresh()->status)->toBe(OperationStatus::Running)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning);
});

it('does not project provider metadata before terminal success', function (): void {
    $operation = savedNativeProvisionIntent();
    app(TaskVmProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => ['provider' => ['address' => '192.0.2.208', 'provider_id' => null, 'ssh_host_key' => null]]]);
    expect($this->vm->fresh()->address)->toBeNull()->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning);
});

it('does not activate the Node from intermediate network progress', function (): void {
    $operation = savedNativeProvisionIntent();
    $guest = $this->vm->fresh()->node;
    $edges = DB::table('node_access')->count();
    app(TaskVmProvisionProjection::class)->apply($operation, ['run_id' => $operation->state['swamp_run_id'], 'status' => 'running',
        'started_at' => '2026-10-31T00:00:00.000Z', 'result' => ['node' => ['network_enrolled' => true]]]);
    expect($guest->fresh()->status)->toBe(LifecycleStatus::Provisioning)
        ->and($this->vm->fresh()->state)->toBe(TaskVmState::Provisioning)
        ->and($guest->roles()->where('role', RoleName::AppDev)->sole()->status)->toBe(LifecycleStatus::Provisioning)
        ->and(DB::table('node_access')->count())->toBe($edges);
});

it('builds authoritative full workflow inputs without a participant read callback', function (): void {
    $operation = savedNativeProvisionIntent();
    $input = app(DirectOperation::class)->workflowInputs($operation);
    expect($input['operation_id'])->toBe($operation->id)->and($input['kind'])->toBe('task_vm.provision')
        ->and($input['node_id'])->toBe($this->host->id)
        ->and($input['intent']['guest_node_id'])->toBe($operation->input['guest_node_id'])
        ->and($input['intent'])->not->toHaveKeys(['execution_secrets', 'configuration', 'worker_credentials']);
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

    $this->getJson($path.'?run_id='.$run)->assertForbidden();
    $this->getJson($path.'?run_id=f5518d34-4395-4454-ab37-7f3cdcae1ad4')->assertForbidden();
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

        if (($message['type'] ?? null) === 'vault.put') {
            return nativeProvisionVaultReply($message);
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

it('pushes the club key execution copy only for a proof group and passes names only', function (bool $proof): void {
    config(['swamp.proof_club_api_key' => 'fixture-proof-club-value']);
    $this->vm->group->project->update(['slug' => $proof ? 'swamp-proof' : 'native-provision']);
    $socket = app(WebSocketClient::class);

    $operation = savedNativeProvisionIntent();
    $envelope = app(TaskVmProvisionIntent::class)->input($operation);

    $key = 'task-vm-proof-club-key-'.$this->vm->id;
    $pushed = array_values(array_filter($socket->sent, static fn (array $message): bool => $message['payload']['key'] === $key));
    expect(json_encode([$operation->input, $envelope], JSON_THROW_ON_ERROR))->not->toContain('fixture-proof-club-value');
    if ($proof) {
        expect($envelope['execution_secrets']['proof_club_key'])->toBe(['vault' => 'orbit-control-inputs', 'key' => $key])
            ->and($pushed)->toHaveCount(1)
            ->and($pushed[0]['payload'])->toMatchArray(['vaultName' => 'orbit-control-inputs', 'value' => 'fixture-proof-club-value', 'force' => true]);
    } else {
        expect($envelope['execution_secrets'])->not->toHaveKey('proof_club_key')->and($pushed)->toBe([]);
    }
})->with(['proof group' => true, 'other group' => false]);

it('refuses a proof group before any vault push when the Gateway has no club key', function (string $value): void {
    config(['swamp.proof_club_api_key' => $value]);
    $this->vm->group->project->update(['slug' => 'swamp-proof']);

    expect(fn () => app(TaskVmProvisionIntent::class)->capture($this->vm))
        ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('task_vm.proof_club_key_missing'));
    expect(app(WebSocketClient::class)->sent)->toBe([]);
})->with(['empty' => '', 'blank' => '  ']);
