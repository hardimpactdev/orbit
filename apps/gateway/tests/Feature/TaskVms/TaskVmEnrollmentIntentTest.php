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
use App\Models\Process;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\TaskVmSettingsBuilder;

function nativeEnrollmentVm(): TaskVm
{
    Http::preventStrayRequests();
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
    TaskVmSettingsBuilder::bind(['devClusterId' => null, 'piArtifactPath' => '/private/pi', 'piArtifactSha256' => str_repeat('a', 64), 'piModels' => ['test-model'], 'hosts' => [TaskVmSettingsBuilder::host($host->id)]]);
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

    $key = base64_encode(pack('N', 11).'ssh-ed25519'.pack('N', 32).str_repeat('k', 32));
    $pin = 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($key, true), true)), '=');
    app(TaskVmEnrollmentProjection::class)->record($vm, $intent, ['ready' => true, 'guest_node_id' => $foreign->id,
        'node' => ['address' => '192.0.2.129', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => $key,
            'ssh_host_fingerprint' => $pin, 'wireguard_public_key' => 'WIREGUARD', 'observer_digest' => str_repeat('d', 64), 'agent_secret_hash' => 'must-not-store']]);

    expect($vm->fresh()->state)->toBe(TaskVmState::Ready);
    expect($vm->fresh()->address)->toBe('192.0.2.129');
    expect($vm->fresh()->node->status)->toBe(LifecycleStatus::Active);
    expect($vm->fresh()->node->ssh_host_fingerprint)->toBe($pin);
    expect($vm->fresh()->node->roles()->sole()->status)->toBe(LifecycleStatus::Active);
    expect($vm->fresh()->node->agent_secret_hash)->toBe(str_repeat('d', 64));
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

it('keeps router teardown fenced until its outer admission callback returns', function (): void {
    $vm = nativeEnrollmentVm();
    $intent = app(TaskVmEnrollmentIntent::class);
    $captured = $intent->admit(function () use ($intent, $vm): array {
        $other = Cache::lock(ModelKeyIssuer::Lock, 600);
        expect($other->get())->toBeFalse();
        $input = $intent->capture($vm);
        expect($vm->fresh()->model_key)->not->toBeNull();
        expect($other->get())->toBeFalse();

        return $input;
    });
    expect($captured['task_vm_id'])->toBe($vm->id);
    $released = Cache::lock(ModelKeyIssuer::Lock, 600);
    expect($released->get())->toBeTrue();
    $released->release();
});

it('keeps one teardown fence through nested allocation and provision admission callbacks', function (): void {
    $vm = nativeEnrollmentVm();
    $intent = app(TaskVmEnrollmentIntent::class);
    $intent->admit(function () use ($vm): void {
        DB::transaction(function () use ($vm): void {
            $nested = app(TaskVmEnrollmentIntent::class);
            $nested->admit(fn (): array => $nested->capture($vm));
            expect(Cache::lock(ModelKeyIssuer::Lock, 600)->get())->toBeFalse();
            expect($vm->fresh()->model_key)->not->toBeNull();
        });
        expect(Cache::lock(ModelKeyIssuer::Lock, 600)->get())->toBeFalse();
    });
    $released = Cache::lock(ModelKeyIssuer::Lock, 600);
    expect($released->get())->toBeTrue();
    $released->release();
});

it('releases the admission scope after an exception without retaining nested ownership', function (): void {
    $intent = app(TaskVmEnrollmentIntent::class);
    expect(fn () => $intent->admit(fn () => $intent->admit(fn () => throw new RuntimeException('admission refused'))))
        ->toThrow(RuntimeException::class, 'admission refused');
    $released = Cache::lock(ModelKeyIssuer::Lock, 600);
    expect($released->get())->toBeTrue();
    $released->release();
    expect($intent->admit(fn (): string => 'fresh admission'))->toBe('fresh admission');
});
