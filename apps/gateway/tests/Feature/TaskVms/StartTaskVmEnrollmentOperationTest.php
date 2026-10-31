<?php

declare(strict_types=1);

use App\Actions\TaskVms\StartTaskVmEnrollmentOperationAction;
use App\Actions\TaskVms\StartTaskVmRuntimeOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\ModelKeyIssuer;
use App\Domain\TaskVms\TaskVmEnrollmentProjection;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Swamp\StartSwampRun;
use App\Infrastructure\WireGuard\VpnConfigurationRepository;
use App\Models\Node;
use App\Models\Process;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

function standaloneEnrollmentVm(): TaskVm
{
    Http::preventStrayRequests();
    $host = Node::query()->create(['name' => 'build-host', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.9', 'wireguard_ip' => '10.44.0.9', 'platform' => 'linux', 'architecture' => 'x86_64']);
    $host->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    TaskVmSettingsBuilder::bind(['devClusterId' => null, 'piArtifactPath' => '/private/pi', 'piArtifactSha256' => str_repeat('a', 64), 'piModels' => ['test-model'], 'hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
    $issuer = Node::query()->create(['name' => 'issuer', 'public_ssh_host' => '192.0.2.209', 'user' => 'orbit', 'platform' => 'linux', 'status' => 'active']);
    $hub = Node::query()->create(['name' => 'vpn', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1', 'status' => 'active']);
    $hub->roles()->create(['role' => RoleName::Vpn, 'status' => LifecycleStatus::Active]);
    config(['dependency_detection.services_node_id' => $issuer->id]);
    $keys = Mockery::mock(ModelKeyIssuer::class);
    $keys->shouldReceive('origin')->andReturn(TaskVmSettingsBuilder::Origin);
    $keys->shouldReceive('assertReady');
    $keys->shouldReceive('ownerNodeId')->andReturn($issuer->id);
    $keys->shouldNotReceive('privateMaterial');
    app()->instance(ModelKeyIssuer::class, $keys);
    $certificates = Mockery::mock(LeafCertificateSigner::class);
    $certificates->shouldReceive('rootCertificate')->andReturn('ROOT CERTIFICATE');
    app()->instance(LeafCertificateSigner::class, $certificates);
    $ssh = Mockery::mock(SshKeyProvider::class);
    $ssh->shouldReceive('publicKey')->andReturn('ssh-ed25519 PUBLIC');
    app()->instance(SshKeyProvider::class, $ssh);
    $home = sys_get_temp_dir().'/standalone-enrollment-'.bin2hex(random_bytes(8));
    mkdir($home.'/wireguard', 0o700, true);
    file_put_contents($home.'/wireguard/private.key', 'PRIVATE-HUB');
    file_put_contents($home.'/wireguard/public.key', 'PUBLIC-HUB');
    test()->beforeApplicationDestroyed(static fn () => new Filesystem()->deleteDirectory($home));
    app()->instance(VpnConfigurationRepository::class, new VpnConfigurationRepository(app(VpnSettings::class), $home));
    $project = Project::query()->create(['name' => 'Standalone enrollment', 'slug' => 'standalone-enrollment', 'repository_url' => 'https://github.com/example/enrollment.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Enroll', 'brief' => 'Prepare the VM', 'status' => 'reserved', 'orchestration' => 'external']);
    $vm = TaskVm::query()->create(['name' => 'pending', 'provider' => 'incus', 'state' => 'provisioning', 'group_id' => $group->id, 'host_node_id' => $host->id, 'wireguard_ip' => '10.44.0.129', 'pi_token' => str_repeat('b', 64)]);
    $vm->update(['name' => 'tvm-'.$vm->id]);

    return $vm;
}

it('records one host-placed enrollment root and starts its native DAG without child execution', function (string $provider): void {
    $vm = standaloneEnrollmentVm();
    $executionNodeId = $vm->host_node_id;
    if ($provider === 'upcloud') {
        $vm->update(['provider' => 'upcloud', 'host_node_id' => null]);
    }
    Queue::fake();
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    $operation = app(StartTaskVmEnrollmentOperationAction::class)->execute($vm->id);
    $again = app(StartTaskVmEnrollmentOperationAction::class)->execute($vm->id);

    expect($operation->kind)->toBe('task_vm.enroll');
    expect($operation->target_type)->toBe('task_vm');
    expect($operation->target_id)->toBe($vm->id);
    expect($operation->node_id)->toBe($executionNodeId)->not->toBe($vm->fresh()->node_id);
    expect($again->id)->toBe($operation->id);
    expect($operation->input['execution_node_id'])->toBe($executionNodeId);
    expect($operation->input['private_bindings']['worker_credentials']['owner_node_id'])->toBe($vm->fresh()->node_id);
    expect($operation->input['model_key'])->toBe(['$secret' => 'task_vm.model_key', 'id' => $vm->id]);
    expect($vm->fresh()->state)->toBe(TaskVmState::Provisioning);
    expect($vm->fresh()->node->status)->toBe(LifecycleStatus::Provisioning);
    expect(Process::query()->sole()->status)->toBe(LifecycleStatus::Provisioning);
    expect(json_encode($operation->input))->not->toContain($vm->fresh()->model_key)->not->toContain('PRIVATE-HUB');
    $this->assertDatabaseCount('operations', 1);
    app(StartSwampRun::class)->start($operation);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-task-vm-enroll');
    expect($socket->sent[0]['payload']['inputs'])->toBe(['operation_id' => $operation->id, 'kind' => 'task_vm.enroll', 'node_id' => $executionNodeId]);
    Queue::assertNothingPushed();
})->with(['incus', 'upcloud']);

it('records runtime preparation on the reserved guest while retaining its admitted first-visit host', function (): void {
    $vm = standaloneEnrollmentVm();
    Queue::fake();
    $socket = new FakeRoutePublicationSocket;
    app()->instance(WebSocketClient::class, $socket);
    config(['swamp.run_token' => 'private.scoped.fixture']);

    $operation = app(StartTaskVmRuntimeOperationAction::class)->execute($vm->id);

    expect($operation->kind)->toBe('task_vm.prepare_runtime');
    expect($operation->node_id)->toBe($vm->fresh()->node_id);
    expect($operation->input['execution_node_id'])->toBe($vm->host_node_id);
    expect(app(TaskVmEnrollmentProjection::class)->input($operation)['guest_node_id'])->toBe($operation->node_id);
    app(StartSwampRun::class)->start($operation);
    expect($socket->sent[0]['payload']['workflowIdOrName'])->toBe('operation-task-vm-prepare-runtime');
    expect($socket->sent[0]['payload']['inputs']['node_id'])->toBe($vm->fresh()->node_id);
    $this->assertDatabaseCount('operations', 1);
    expect($vm->fresh()->state)->toBe(TaskVmState::Provisioning);
    Queue::assertNothingPushed();
});

it('ignores absent or terminal VMs without recording another intent', function (): void {
    expect(app(StartTaskVmEnrollmentOperationAction::class)->execute(PHP_INT_MAX))->toBeNull();
    $vm = standaloneEnrollmentVm();
    $vm->update(['state' => TaskVmState::Failed]);
    expect(app(StartTaskVmEnrollmentOperationAction::class)->execute($vm->id))->toBeNull();
    expect($vm->fresh()->node_id)->toBeNull();
    $this->assertDatabaseCount('operations', 0);
    $this->assertDatabaseCount('processes', 0);
});

it('permits a new enrollment intent after a failed admission without replacing the guest identity', function (): void {
    $vm = standaloneEnrollmentVm();
    app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
    config(['swamp.run_token' => 'private.scoped.fixture']);
    $first = app(StartTaskVmEnrollmentOperationAction::class)->execute($vm->id);
    $guest = $vm->fresh()->node_id;
    $first->update(['status' => OperationStatus::Failed]);

    $next = app(StartTaskVmEnrollmentOperationAction::class)->execute($vm->id);

    expect($next->id)->not->toBe($first->id);
    expect($next->node_id)->toBe($vm->host_node_id);
    expect($vm->fresh()->node_id)->toBe($guest);
    $this->assertDatabaseCount('operations', 2);
    $this->assertDatabaseCount('nodes', 4);
    $this->assertDatabaseCount('processes', 1);
});
