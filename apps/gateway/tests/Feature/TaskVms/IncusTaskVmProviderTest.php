<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\TaskVms\GuestBootstrap;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmProvider;
use App\Infrastructure\TaskVms\TaskVmGuest;
use App\Infrastructure\TaskVms\TaskVmVolumes;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeGuestAgentCommandRunner;
use Tests\Support\TaskVmSettingsBuilder;

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo']);
    $host = Node::query()->create([
        'name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'orbit',
    ]);
    $this->vm = TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => $host->id, 'provider' => 'incus', 'name' => 'tvm-1',
        'state' => TaskVmState::Provisioning, 'wireguard_ip' => '10.44.0.130', 'pi_token' => 'pi-secret',
    ]);
    $settings = TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($host->id, ['maxVms' => 4, 'pool' => 'tank'])]]);

    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $incus = new IncusHost($this->agents);
    $this->guestAgents = new FakeGuestAgentCommandRunner;
    $this->provider = new IncusTaskVmProvider($incus, $settings, new GuestBootstrap($this->guestAgents));
    $this->host = $host;
    $this->hostSettings = $settings->host($host->id);
    $this->guest = new TaskVmGuest($this->provider);
});

it('sends creation intent with the claimed image and resolved size', function (TaskVmSize $size, int $cpus, string $memory, string $disk, bool $topology): void {
    $fingerprint = str_repeat('c', 64);
    $this->vm->update(['size' => $size, 'recipe_identity' => 'sha256:'.str_repeat('a', 64), 'image_fingerprint' => $fingerprint]);
    $this->agents->answer('incus.vm', ['ready' => true]);

    $this->provider->create($this->vm, 'ssh-ed25519 AAAA gateway');

    expect($this->agents->calls)->toBe([['node' => 'beast', 'command' => 'incus.vm', 'input' => [
        'vm' => ['project' => 'orbit-tasks', 'name' => 'tvm-1', 'cidr' => '10.251.77.0/24'],
        'operation' => [
            'kind' => 'create', 'image' => $fingerprint, 'expected_fingerprint' => $fingerprint,
            'base_alias' => 'orbit-task-base', 'gateway_public_key' => 'ssh-ed25519 AAAA gateway',
            'cpus' => $cpus, 'memory' => $memory, 'disk' => $disk, 'network' => 'orbittask0', 'pool' => 'tank', 'topology' => $topology,
        ],
    ]]]);
})->with([
    [TaskVmSize::Normal, 2, '4GiB', '20GiB', false],
    [TaskVmSize::Medium, 4, '8GiB', '40GiB', true],
    [TaskVmSize::Large, 6, '12GiB', '60GiB', true],
]);

it('selects the configured alias only when the row has no recorded image', function (): void {
    $this->agents->answer('incus.vm', ['ready' => true]);

    $this->provider->create($this->vm, 'ssh-ed25519 AAAA gateway');

    expect($this->agents->calls[0]['input']['operation']['image'])->toBe('orbit-task-base');
    expect($this->agents->calls[0]['input']['operation']['expected_fingerprint'])->toBeNull();
});

it('returns the typed native observation', function (?array $state, ?VmObservation $expected): void {
    $this->agents->answer('incus.vm-state', ['observation' => $state]);

    expect($this->provider->observe($this->vm))->toEqual($expected);
    expect($this->agents->calls)->toBe([['node' => 'beast', 'command' => 'incus.vm-state', 'input' => ['project' => 'orbit-tasks', 'name' => 'tvm-1', 'cidr' => '10.251.77.0/24']]]);
})->with([
    [null, null],
    [['running' => true, 'address' => '10.251.77.20', 'image_fingerprint' => str_repeat('c', 64)], new VmObservation(true, '10.251.77.20', str_repeat('c', 64))],
    [['running' => false, 'address' => null, 'image_fingerprint' => null], new VmObservation(false, null)],
]);

it('rejects incomplete native observations', function (array $output): void {
    $this->agents->answerMalformed('incus.vm-state', $output);

    expect(fn () => $this->provider->observe($this->vm))->toThrow(TaskVmException::class);
})->with([[[]], [['observation' => []]], [['observation' => ['running' => true, 'address' => null, 'image_fingerprint' => 42]]]]);

it('maps VM lifecycle refusals without a second host execution path', function (string $code, array $details, string $message): void {
    $this->agents->refuse('incus.vm', $code, $details);

    expect(fn () => $this->provider->create($this->vm, 'ssh-ed25519 AAAA gateway'))
        ->toThrow(fn (TaskVmException $error) => expect([$error->errorCode, $error->getMessage()])->toBe([$code, $message]));
    expect($this->agents->calls)->toHaveCount(1);
})->with([
    ['task_vm.vm_missing', [], 'Task VM [tvm-1] is gone from its host.'],
    ['task_vm.image_mismatch', [], 'The task VM did not launch its claimed recipe image.'],
    ['task_vm.topology_seed_missing', [], 'The host of task VM [tvm-1] has no topology seed for its base image. Build it with task-vms:build-image.'],
    ['task_vm.base_image_missing', [], 'The Incus project [orbit-tasks] on the host of task VM [tvm-1] has no base image [orbit-task-base]. Build it with task-vms:build-image.'],
    ['task_vm.invalid_host_output', ['reason' => 'invalid observation'], 'Incus returned invalid output for task VM [tvm-1]: invalid observation.'],
    ['task_vm.host_command_failed', ['operation' => 'launch', 'exit_code' => 1], '`incus launch` for task VM [tvm-1] failed with exit code [1].'],
]);

it('sends start stop and delete intent to the native lifecycle command', function (string $method, array $operation): void {
    $this->agents->answer('incus.vm', ['ready' => true]);

    $this->provider->{$method}($this->vm);

    expect($this->agents->calls)->toBe([['node' => 'beast', 'command' => 'incus.vm', 'input' => [
        'vm' => ['project' => 'orbit-tasks', 'name' => 'tvm-1', 'cidr' => '10.251.77.0/24'], 'operation' => $operation,
    ]]]);
})->with([['start', ['kind' => 'start']], ['stop', ['kind' => 'stop']], ['destroy', ['kind' => 'delete', 'pool' => 'tank']]]);

it('reads typed readiness and fingerprint through the trusted guest target', function (bool $ready): void {
    $pin = 'SHA256:'.str_repeat('a', 43);
    $this->guestAgents->answer = static fn ($guest, string $command): array => $command === 'guest.cloud-init' ? ['ready' => $ready] : ['fingerprint' => $pin];

    expect($this->guest->bootstrapReady($this->vm))->toBe($ready)
        ->and($this->provider->sshHostFingerprint($this->vm))->toBe($pin);
    expect(array_column($this->guestAgents->calls, 'command'))->toBe(['guest.cloud-init', 'guest.fingerprint']);
    expect($this->guestAgents->calls[0]['guest']->name)->toBe('tvm-1')
        ->and($this->guestAgents->calls[0]['guest']->host->is($this->host))->toBeTrue();
})->with([true, false]);

it('owns the clones of VMs and only the topology seeds of images the host keeps', function (): void {
    $kept = [str_repeat('c', 64)];

    expect(TaskVmVolumes::owns('tvm-7-topology-seed', $kept))->toBeTrue()
        ->and(TaskVmVolumes::owns('tvm-7-workspace', $kept))->toBeFalse()
        ->and(TaskVmVolumes::owns('orbit-topology-seed-cccccccccccc', $kept))->toBeTrue()
        ->and(TaskVmVolumes::owns('orbit-topology-seed-cccccccccccc-new', $kept))->toBeTrue()
        ->and(TaskVmVolumes::owns('orbit-topology-seed-dddddddddddd', $kept))->toBeFalse()
        ->and(TaskVmVolumes::owns('tvm-template-1-20261011040000', $kept))->toBeFalse()
        ->and(TaskVmVolumes::seed(str_repeat('c', 64)))->toBe('orbit-topology-seed-cccccccccccc')
        ->and(TaskVmVolumes::newSeed(str_repeat('c', 64)))->toBe('orbit-topology-seed-cccccccccccc-new');
});
