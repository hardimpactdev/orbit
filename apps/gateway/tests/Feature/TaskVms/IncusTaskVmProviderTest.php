<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
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

const INCUS_PREFIX = ['sudo', '-n', 'incus', '--project', 'orbit-tasks'];

function incusResult(string $stdout = '', int $exitCode = 0, string $stderr = '', bool $truncated = false): CommandResult
{
    return new CommandResult($exitCode, $stdout, $stderr, 5, $truncated);
}

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

    $this->ssh = new class implements SshExecutor
    {
        /** @var list<array{SshConnection, RemoteCommand}> */
        public array $calls = [];

        /** @var list<CommandResult> */
        public array $results = [];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls[] = [$connection, $command];

            return array_shift($this->results) ?? throw new RuntimeException('Unexpected SSH command: '.$command->shellCommand());
        }
    };
    $this->agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $this->agents);
    $incus = new IncusHost($this->ssh, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA gateway';
        }
    }, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    }, app(AgentCommandRunner::class));
    $this->volumes = new TaskVmVolumes($incus);
    $this->guestAgents = new FakeGuestAgentCommandRunner;
    $this->provider = new IncusTaskVmProvider($incus, $settings, new GuestBootstrap($this->guestAgents));
    $this->host = $host;
    $this->hostSettings = $settings->host($host->id);
    $this->guest = new TaskVmGuest($this->provider);
    $this->answer = function (CommandResult ...$results): void {
        $this->ssh->results = $results;
    };
    $this->argv = fn (): array => array_map(static fn (array $call): array => $call[1]->arguments, $this->ssh->calls);
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
    expect($this->ssh->calls)->toBe([]);
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
    expect($this->ssh->calls)->toBe([]);
})->with([
    [null, null],
    [['running' => true, 'address' => '10.251.77.20', 'image_fingerprint' => str_repeat('c', 64)], new VmObservation(true, '10.251.77.20', str_repeat('c', 64))],
    [['running' => false, 'address' => null, 'image_fingerprint' => null], new VmObservation(false, null)],
]);

it('rejects incomplete native observations', function (array $output): void {
    $this->agents->answer('incus.vm-state', $output);

    expect(fn () => $this->provider->observe($this->vm))->toThrow(TaskVmException::class);
})->with([[[]], [['observation' => []]], [['observation' => ['running' => true, 'address' => null, 'image_fingerprint' => 42]]]]);

it('maps VM lifecycle refusals without a second host execution path', function (string $code, array $details, string $message): void {
    $this->agents->refuse('incus.vm', $code, $details);

    expect(fn () => $this->provider->create($this->vm, 'ssh-ed25519 AAAA gateway'))
        ->toThrow(fn (TaskVmException $error) => expect([$error->errorCode, $error->getMessage()])->toBe([$code, $message]));
    expect($this->ssh->calls)->toBe([]);
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
    expect($this->ssh->calls)->toBe([]);
})->with([['start', ['kind' => 'start']], ['stop', ['kind' => 'stop']], ['destroy', ['kind' => 'delete', 'pool' => 'tank']]]);

it('reads typed readiness and fingerprint through the trusted guest target', function (bool $ready): void {
    $pin = 'SHA256:'.str_repeat('a', 43);
    $this->guestAgents->answer = static fn ($guest, string $command): array => $command === 'guest.cloud-init' ? ['ready' => $ready] : ['fingerprint' => $pin];

    expect($this->guest->bootstrapReady($this->vm))->toBe($ready)
        ->and($this->provider->sshHostFingerprint($this->vm))->toBe($pin);
    expect(array_column($this->guestAgents->calls, 'command'))->toBe(['guest.cloud-init', 'guest.fingerprint']);
    expect($this->guestAgents->calls[0]['guest']->name)->toBe('tvm-1')
        ->and($this->guestAgents->calls[0]['guest']->host->is($this->host))->toBeTrue()
        ->and($this->ssh->calls)->toBe([]);
})->with([true, false]);

it('sweeps only the clones of VMs without live rows, and keeps topology seeds', function (): void {
    $this->vm->update(['state' => TaskVmState::Parked]);
    $this->agents->answer('incus.volumes', ['names' => ['tvm-1-topology-seed', 'tvm-999-topology-seed', 'orbit-topology-seed-cccccccccccc', 'tvm-template-1-20261011040000', 'operator-data']]);
    $this->agents->answer('incus.volume', ['name' => 'tvm-999-topology-seed']);

    expect($this->volumes->sweepClones($this->hostSettings, $this->host))->toBe(['tvm-999-topology-seed']);
    expect($this->agents->calls)->toBe([
        ['node' => 'beast', 'command' => 'incus.volumes', 'input' => ['project' => 'orbit-tasks', 'pool' => 'tank']],
        ['node' => 'beast', 'command' => 'incus.volume', 'input' => ['location' => ['project' => 'orbit-tasks', 'pool' => 'tank'], 'operation' => ['kind' => 'remove', 'name' => 'tvm-999-topology-seed']]],
    ]);
});

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

it('keeps a clone when Incus refuses its deletion and reports the failure', function (): void {
    $this->agents->refuse('incus.volume', 'task_vm.host_command_failed', ['operation' => 'storage volume delete', 'exit_code' => 1]);

    expect(fn () => $this->volumes->release($this->hostSettings, $this->host, $this->vm))->toThrow(TaskVmException::class);
});
