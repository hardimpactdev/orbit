<?php

declare(strict_types=1);

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmEnrollmentIntent;
use App\Domain\TaskVms\TaskVmEnrollmentProjection;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskVmSettingsBuilder;

function nativeEnrollmentVm(): TaskVm
{
    Http::preventStrayRequests();
    TaskVmSettingsBuilder::bind(['devClusterId' => null, 'piArtifactPath' => '/private/pi', 'piArtifactSha256' => str_repeat('a', 64), 'piModels' => ['test-model']]);
    $keys = Mockery::mock(ModelKeyIssuer::class);
    $keys->shouldReceive('origin')->andReturn(TaskVmSettingsBuilder::Origin);
    $keys->shouldReceive('assertReady');
    $keys->shouldNotReceive('issue');
    $keys->shouldNotReceive('revoke');
    app()->instance(ModelKeyIssuer::class, $keys);
    $certificates = Mockery::mock(LeafCertificateSigner::class);
    $certificates->shouldReceive('rootCertificate')->andReturn('ROOT CERTIFICATE');
    app()->instance(LeafCertificateSigner::class, $certificates);
    $ssh = Mockery::mock(SshKeyProvider::class);
    $ssh->shouldReceive('publicKey')->andReturn('ssh-ed25519 PUBLIC');
    app()->instance(SshKeyProvider::class, $ssh);
    $project = Project::query()->create(['name' => 'Native enrollment', 'slug' => 'native-enrollment', 'repository_url' => 'https://github.com/example/native.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Enroll', 'brief' => 'Prepare the VM', 'status' => 'reserved',
        'orchestration' => 'external', 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi']);
    $host = Node::query()->create(['name' => 'build-host', 'status' => LifecycleStatus::Active, 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9',
        'wireguard_ip' => '10.44.0.9', 'platform' => 'linux', 'architecture' => 'x86_64']);
    $host->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $vm = TaskVm::query()->create(['name' => 'pending', 'provider' => 'incus', 'state' => 'provisioning', 'group_id' => $group->id,
        'host_node_id' => $host->id, 'wireguard_ip' => '10.44.0.129', 'pi_token' => str_repeat('b', 64)]);
    $vm->update(['name' => 'tvm-'.$vm->id]);

    return $vm;
}

it('records one workerless guest and Pi Process intent without running or admitting children', function (): void {
    $vm = nativeEnrollmentVm();
    Queue::fake();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    $agents->shouldNotReceive('install');
    app()->instance(AgentCommandRunner::class, $agents);

    $intent = app(TaskVmEnrollmentIntent::class)->capture($vm);
    $again = app(TaskVmEnrollmentIntent::class)->capture($vm);

    expect($again)->toBe($intent);
    expect($vm->fresh()->node)->toMatchArray(['name' => $vm->name, 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => '10.44.0.129', 'ssh_jump_node_id' => $vm->host_node_id]);
    expect($vm->fresh()->node->status)->toBe(LifecycleStatus::Provisioning);
    expect($vm->fresh()->state)->toBe(TaskVmState::Provisioning);
    expect($vm->fresh()->node->roles()->sole()->status)->toBe(LifecycleStatus::Provisioning);
    expect($intent['bootstrap']['public_key'])->toBe('ssh-ed25519 PUBLIC');
    expect($intent['agent']['configuration'])->not->toContain('[swamp_worker]');
    expect($intent['execution_node_id'])->toBe($vm->host_node_id);
    expect($intent['runtime']['process']['process_id'])->toBe(Process::query()->sole()->id);
    expect(Process::query()->sole()->status)->toBe(LifecycleStatus::Provisioning);
    expect(hash('sha256', app(TaskVmEnrollmentIntent::class)->installation($vm)['content']))->toBe($intent['runtime']['process']['content_hash']);
    expect(json_encode($intent, JSON_THROW_ON_ERROR))->not->toContain($vm->fresh()->model_key)->not->toContain(str_repeat('b', 64))->not->toContain('/private/pi');
    $this->assertDatabaseCount('operations', 0);
    $this->assertDatabaseCount('processes', 1);
    Queue::assertNothingPushed();
});

it('refuses a same-name foreign Node before reserving enrollment or credentials', function (): void {
    $vm = nativeEnrollmentVm();
    Node::query()->create(['name' => $vm->name, 'status' => LifecycleStatus::Active, 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.55']);

    expect(fn () => app(TaskVmEnrollmentIntent::class)->capture($vm))->toThrow(fn (TaskVmException $error) => expect($error->errorCode)->toBe('task_vm.node_ownership_conflict'));
    expect($vm->fresh()->node_id)->toBeNull();
    expect($vm->fresh()->model_key)->toBeNull();
    $this->assertDatabaseCount('processes', 0);
});

it('refuses a conflicting Pi definition and retains the original credential', function (): void {
    $vm = nativeEnrollmentVm();
    app(TaskVmEnrollmentIntent::class)->capture($vm);
    $key = $vm->fresh()->model_key;
    Process::query()->sole()->update(['runtime_config' => ['command' => ['/bin/false']]]);

    expect(fn () => app(TaskVmEnrollmentIntent::class)->capture($vm))->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('process.name_taken'));
    expect($vm->fresh()->model_key)->toBe($key);
    expect(Process::query()->sole()->runtime_config)->toBe(['command' => ['/bin/false']]);
    $this->assertDatabaseCount('operations', 0);
});

it('records the native result on intent-selected resources without child proofs or result-selected targets', function (): void {
    $vm = nativeEnrollmentVm();
    $intent = app(TaskVmEnrollmentIntent::class)->capture($vm);
    $foreign = Node::query()->create(['name' => 'foreign', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.60', 'status' => LifecycleStatus::Failed]);

    app(TaskVmEnrollmentProjection::class)->record($vm, $intent, ['ready' => true, 'guest_node_id' => $foreign->id,
        'node' => ['address' => '192.0.2.129', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'PUBLIC',
            'ssh_host_fingerprint' => 'SHA256:pinned', 'wireguard_public_key' => 'WIREGUARD', 'agent_secret_hash' => 'must-not-store']]);

    expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
    expect($vm->fresh()->address)->toBe('192.0.2.129');
    expect($vm->fresh()->node->status)->toBe(LifecycleStatus::Active);
    expect($vm->fresh()->node->ssh_host_fingerprint)->toBe('SHA256:pinned');
    expect($vm->fresh()->node->roles()->sole()->status)->toBe(LifecycleStatus::Active);
    expect($vm->fresh()->node->agent_secret_hash)->toBeNull();
    expect($foreign->fresh()->status)->toBe(LifecycleStatus::Failed);
    expect(Process::query()->sole()->status)->toBe(LifecycleStatus::Active);
    expect(Process::query()->sole()->systemd_activation_hash)->toBe($intent['runtime']['process']['content_hash']);
    $this->assertDatabaseCount('operations', 0);
});

it('does not revive a VM destroyed before native enrollment finishes', function (): void {
    $vm = nativeEnrollmentVm();
    $intent = app(TaskVmEnrollmentIntent::class)->capture($vm);
    $vm->update(['state' => TaskVmState::Destroyed]);
    $vm->node->update(['status' => LifecycleStatus::Failed]);

    app(TaskVmEnrollmentProjection::class)->record($vm, $intent, ['ready' => true]);

    expect($vm->fresh()->state)->toBe(TaskVmState::Destroyed);
    expect($vm->fresh()->node->status)->toBe(LifecycleStatus::Failed);
    expect(Process::query()->sole()->status)->toBe(LifecycleStatus::Provisioning);
});

it('places UpCloud enrollment on the active Gateway and reserves a guest without a jump host', function (): void {
    $vm = nativeEnrollmentVm();
    $gatewayId = $vm->host_node_id;
    $vm->update(['provider' => 'upcloud', 'host_node_id' => null]);

    $intent = app(TaskVmEnrollmentIntent::class)->capture($vm);

    expect($intent['execution_node_id'])->toBe($gatewayId);
    expect($intent['provider'])->toBe('upcloud');
    expect($vm->fresh()->node->ssh_jump_node_id)->toBeNull();
    expect($vm->fresh()->node->status)->toBe(LifecycleStatus::Provisioning);
});

it('refuses enrollment without a task group before reserving a Node or credentials', function (): void {
    $vm = nativeEnrollmentVm();
    $vm->update(['group_id' => null]);

    expect(fn () => app(TaskVmEnrollmentIntent::class)->capture($vm))->toThrow(fn (TaskVmException $error) => expect($error->errorCode)->toBe('task_vm.enrollment_invalid'));
    expect($vm->fresh()->node_id)->toBeNull();
    expect($vm->fresh()->model_key)->toBeNull();
    $this->assertDatabaseCount('processes', 0);
});

it('keeps private references and credentials out of the worker input', function (): void {
    $vm = nativeEnrollmentVm();
    $intent = app(TaskVmEnrollmentIntent::class)->capture($vm);
    $operation = new Operation(['kind' => 'task_vm.enroll', 'target_type' => 'task_vm', 'target_id' => $vm->id,
        'input' => [...$intent, 'model_key' => $vm->fresh()->model_key, 'pi_token' => $vm->pi_token,
            'pi_artifact_file' => ['$secret' => 'task_vm.pi_artifact', 'id' => $vm->id]]]);

    $input = app(TaskVmEnrollmentProjection::class)->input($operation);

    expect($input)->toBe([...$intent, 'wireguard_public_key' => null]);
});

it('records the native public network observation without marking the guest or VM ready', function (): void {
    $vm = nativeEnrollmentVm();
    $intent = app(TaskVmEnrollmentIntent::class)->capture($vm);
    app(TaskVmEnrollmentProjection::class)->recordPublic($vm, $intent, ['node' => [
        'wireguard_public_key' => 'PUBLIC PEER KEY', 'agent_secret_hash' => str_repeat('c', 64),
    ]]);
    expect($vm->fresh()->node->wireguard_public_key)->toBe('PUBLIC PEER KEY');
    expect($vm->fresh()->node->agent_secret_hash)->toBeNull();
    expect($vm->fresh()->node->status)->toBe(LifecycleStatus::Provisioning);
    expect($vm->fresh()->state)->toBe(TaskVmState::Provisioning);
    expect(Process::query()->sole()->status)->toBe(LifecycleStatus::Provisioning);
});

it('admits immutable field custody without exporting private material or invoking a worker', function (): void {
    $vm = nativeEnrollmentVm();
    $intent = app(TaskVmEnrollmentIntent::class)->capture($vm);
    $issuer = Node::query()->create(['name' => 'native-issuer', 'public_ssh_host' => '192.0.2.209', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $router = Node::query()->create(['name' => 'model-router', 'public_ssh_host' => '192.0.2.3', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $hub = Node::query()->create(['name' => 'vpn', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'status' => 'active']);
    $hub->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    config()->set('dependency_detection.services_node_id', $issuer->id);
    app(ModelKeyIssuer::class)->shouldReceive('ownerNodeId')->once()->andReturn($router->id);
    $home = sys_get_temp_dir().'/native-enrollment-'.bin2hex(random_bytes(8));
    mkdir($home.'/wireguard', 0o700, true);
    file_put_contents($home.'/wireguard/private.key', 'PRIVATE-HUB');
    file_put_contents($home.'/wireguard/public.key', 'PUBLIC-HUB');
    app()->instance(App\Infrastructure\WireGuard\VpnConfigurationRepository::class,
        new App\Infrastructure\WireGuard\VpnConfigurationRepository(app(App\Domain\WireGuard\VpnSettings::class), $home));
    try {
        $private = app(TaskVmEnrollmentIntent::class)->privateInput($vm, $vm->host_node_id);
        expect($private['participant_node_ids'])->toContain($vm->host_node_id, $issuer->id, $router->id, $vm->node_id, $hub->id);
        expect($private['private_bindings']['worker_credentials'])->toBe(['owner_node_id' => $vm->node_id, 'read_node_ids' => [$vm->host_node_id], 'write_node_ids' => [$issuer->id]]);
        expect($private['private_bindings']['installation'])->toBe(['owner_node_id' => $vm->node_id, 'read_node_ids' => [$vm->node_id], 'write_node_ids' => []]);
        expect($private['private_bindings']['model_router']['owner_node_id'])->toBe($router->id);
        $operation = new Operation(['kind' => 'task_vm.enroll', 'target_type' => 'task_vm', 'target_id' => $vm->id, 'input' => [...$intent, ...$private]]);
        $public = app(TaskVmEnrollmentProjection::class)->input($operation);
        expect(json_encode($public))->not->toContain('$secret')->not->toContain('PRIVATE-HUB')->not->toContain($vm->fresh()->model_key);
        expect(app(TaskVmEnrollmentIntent::class)->installation($vm)['environment'])->toContain('#');
        $this->assertDatabaseCount('operations', 0);
    } finally {
        new Illuminate\Filesystem\Filesystem()->deleteDirectory($home);
    }
});
