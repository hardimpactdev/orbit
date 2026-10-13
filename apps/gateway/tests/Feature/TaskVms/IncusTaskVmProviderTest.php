<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCompute;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\IncusTaskVmProvider;
use App\Infrastructure\TaskVms\TaskVmGuest;
use App\Infrastructure\TaskVms\TaskVmWorkspaceTemplates;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Tests\Support\TaskVmSettingsBuilder;

const INCUS_PREFIX = ['sudo', '-n', 'incus', '--project', 'orbit-tasks'];

/** @param  list<array{address: string, family?: string}>  $addresses */
function incusInstance(string $name = 'tvm-1', string $status = 'Running', array $addresses = [['address' => '10.251.77.20']], string $type = 'virtual-machine'): array
{
    return ['name' => $name, 'type' => $type, 'status' => $status, 'state' => ['network' => [
        'enp5s0' => ['addresses' => array_map(static fn (array $address): array => ['family' => 'inet', 'scope' => 'global', ...$address], $addresses)],
        'lo' => ['addresses' => [['family' => 'inet', 'address' => '127.0.0.1', 'scope' => 'local']]],
    ]]];
}

function incusResult(string $stdout = '', int $exitCode = 0, string $stderr = '', bool $truncated = false): CommandResult
{
    return new CommandResult($exitCode, $stdout, $stderr, 5, $truncated);
}

/** @param  list<string>  ...$aliases  the aliases of each image */
function incusImages(array ...$aliases): CommandResult
{
    return incusResult(json_encode(array_map(static fn (array $names, int $index): array => [
        'fingerprint' => str_repeat(dechex($index + 10), 64),
        'aliases' => array_map(static fn (string $name): array => ['name' => $name, 'description' => ''], $names),
    ], $aliases, array_keys($aliases)), JSON_THROW_ON_ERROR));
}

function incusList(array ...$instances): CommandResult
{
    return incusResult(json_encode($instances, JSON_THROW_ON_ERROR));
}

beforeEach(function (): void {
    $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
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
    });
    $this->provider = new IncusTaskVmProvider($incus, $settings, new TaskVmWorkspaceTemplates($incus, $settings, app(DevelopmentSshExecutor::class), app(ManagedUserAccountResolver::class)));
    $this->guest = new TaskVmGuest($this->provider);
    $this->answer = function (CommandResult ...$results): void {
        $this->ssh->results = $results;
    };
    $this->argv = fn (): array => array_map(static fn (array $call): array => $call[1]->arguments, $this->ssh->calls);
});

it('launches an absent VM on the configured bridge with the user-data on stdin', function (): void {
    ($this->answer)(incusList(incusInstance('tvm-12')), incusResult());

    $this->provider->create($this->vm, "#cloud-config\nshell: /bin/bash\n");

    [$connection, $launch] = $this->ssh->calls[1];
    expect(($this->argv)())->toBe([
        [...INCUS_PREFIX, 'list', '--format', 'json', '--', 'tvm-1'],
        [...INCUS_PREFIX, 'launch', '--vm', '--config', 'limits.cpu=2', '--config', 'limits.memory=4GiB', '--device', 'root,size=20GiB',
            '--device', 'eth0,network=orbittask0', '--device', 'eth0,security.port_isolation=true', '--', 'orbit-task-base', 'tvm-1'],
    ])
        ->and($launch->input)->toBe('{"config":{"cloud-init.user-data":"#cloud-config\nshell: /bin/bash\n"}}')
        ->and([$connection->host, $connection->user, $connection->port, $connection->identityFile, $connection->knownHostsFile])
        ->toBe(['10.44.0.7', 'orbit', 22, '/keys/id_ed25519', '/keys/known_hosts']);
});

it('launches a large VM with its own size and free page reporting on its memory balloon', function (): void {
    $this->vm->update(['size' => TaskVmSize::Large]);
    ($this->answer)(incusList(incusInstance('tvm-12')), incusResult());

    $this->provider->create($this->vm, "#cloud-config\n");

    expect(($this->argv)()[1])->toBe([...INCUS_PREFIX, 'launch', '--vm', '--config', 'limits.cpu=6', '--config', 'limits.memory=12GiB', '--device', 'root,size=60GiB',
        '--device', 'eth0,network=orbittask0', '--device', 'eth0,security.port_isolation=true', '--', 'orbit-task-base', 'tvm-1'])
        ->and(json_decode($this->ssh->calls[1][1]->input ?? '', true))->toBe(['config' => [
            'cloud-init.user-data' => "#cloud-config\n",
            'raw.qemu.conf' => "[device \"qemu_balloon\"]\nfree-page-reporting = \"on\"\n",
        ]]);
});

it('launches a medium VM with 4 vCPU, 8 GiB, and the host disk', function (): void {
    $this->vm->update(['size' => TaskVmSize::Medium]);
    ($this->answer)(incusList(incusInstance('tvm-12')), incusResult());

    $this->provider->create($this->vm, "#cloud-config\n");

    expect(($this->argv)()[1])->toBe([...INCUS_PREFIX, 'launch', '--vm', '--config', 'limits.cpu=4', '--config', 'limits.memory=8GiB', '--device', 'root,size=20GiB',
        '--device', 'eth0,network=orbittask0', '--device', 'eth0,security.port_isolation=true', '--', 'orbit-task-base', 'tvm-1'])
        ->and(json_decode($this->ssh->calls[1][1]->input ?? '', true))->toBe(['config' => ['cloud-init.user-data' => "#cloud-config\n"]]);
});

it('does not launch a VM that runs', function (): void {
    ($this->answer)(incusList(incusInstance()));

    $this->provider->create($this->vm, '#cloud-config');

    expect($this->ssh->calls)->toHaveCount(1);
});

it('starts a VM that exists but is stopped', function (): void {
    ($this->answer)(incusList(incusInstance(status: 'Stopped')), incusResult());
    $this->provider->create($this->vm, '#cloud-config');
    expect(($this->argv)()[1])->toBe([...INCUS_PREFIX, 'start', '--', 'tvm-1']);

    // A guest reboot: start fails, but the VM runs again.
    ($this->answer)(incusList(incusInstance(status: 'Stopped')), incusResult(exitCode: 1, stderr: 'Error: The instance is already running'), incusList(incusInstance()));
    $this->provider->create($this->vm, '#cloud-config');

    $stderr = "Error: Failed to run: qemu exit status 1\n".str_repeat('x', 3000);
    ($this->answer)(incusList(incusInstance(status: 'Stopped')), incusResult(exitCode: 1, stderr: $stderr), incusList(incusInstance(status: 'Stopped')));
    expect(fn () => $this->provider->create($this->vm, '#cloud-config'))
        ->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, mb_strlen($e->getMessage()) < 2100, str_starts_with($e->getMessage(), "`incus start` for task VM [tvm-1] failed with exit code [1].\nError: Failed to run: qemu exit status 1")])
            ->toBe(['task_vm.host_command_failed', true, true]));
});

it('accepts a failed launch only when the VM runs afterwards', function (): void {
    ($this->answer)(incusList(), incusResult(exitCode: 1, stderr: 'Error: connection lost'), incusList(incusInstance()));
    $this->provider->create($this->vm, '#cloud-config');

    ($this->answer)(incusList(), incusResult(exitCode: 1, stderr: "Error: Invalid devices\n"), incusList(), incusImages(['orbit-task-base', 'orbit-task-base-20261010030000']));
    expect(fn () => $this->provider->create($this->vm, '#cloud-config'))
        ->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->getMessage()])->toBe(['task_vm.host_command_failed', "`incus launch` for task VM [tvm-1] failed with exit code [1].\nError: Invalid devices"]));

    // The launch created the VM but could not start it: the launch error is the answer.
    ($this->answer)(incusList(), incusResult(exitCode: 1, stderr: "Error: Failed to run: qemu exit status 1\n"), incusList(incusInstance(status: 'Stopped')), incusImages(['orbit-task-base']));
    expect(fn () => $this->provider->create($this->vm, '#cloud-config'))
        ->toThrow(TaskVmException::class, "`incus launch` for task VM [tvm-1] failed with exit code [1].\nError: Failed to run: qemu exit status 1");
});

it('names a missing base image instead of the launch error, and never launches the stock image', function (): void {
    ($this->answer)(incusList(), incusResult(exitCode: 1, stderr: "Error: Image not found\n"), incusList(), incusImages(['ubuntu-26.04-vm'], ['orbit-task-base-20261010030000']));

    expect(fn () => $this->provider->create($this->vm, '#cloud-config'))
        ->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->getMessage()])->toBe([
            'task_vm.base_image_missing',
            'The Incus project [orbit-tasks] on the host of task VM [tvm-1] has no base image [orbit-task-base]. Sync it with task-vms:sync-image, or build it with task-vms:build-image.',
        ]));
    expect(($this->argv)()[1])->toContain('orbit-task-base')->not->toContain('ubuntu-26.04-vm')
        ->and(($this->argv)()[3])->toBe([...INCUS_PREFIX, 'image', 'list', '--format', 'json']);
});

it('observes the VM by its exact name', function (): void {
    ($this->answer)(incusList(incusInstance('tvm-12'), incusInstance()));
    expect($this->provider->observe($this->vm))->toEqual(new VmObservation(true, '10.251.77.20'));

    ($this->answer)(incusList(incusInstance('tvm-12')));
    expect($this->provider->observe($this->vm))->toBeNull();

    $stopped = incusInstance(status: 'Stopped');
    $stopped['state']['network'] = null;
    ($this->answer)(incusList($stopped));
    expect($this->provider->observe($this->vm))->toEqual(new VmObservation(false, null));

    ($this->answer)(incusList(incusInstance(addresses: [['address' => '10.44.0.130'], ['address' => 'fe80::1', 'family' => 'inet6']])));
    expect($this->provider->observe($this->vm))->toEqual(new VmObservation(true, null));
});

it('refuses invalid instance output', function (CommandResult $result): void {
    ($this->answer)($result);

    expect(fn () => $this->provider->observe($this->vm))
        ->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->status])->toBe(['task_vm.invalid_host_output', 502]));
})->with([
    'not JSON' => [incusResult('Error')],
    'truncated' => [incusResult('[]', truncated: true)],
    'not a list' => [incusResult('{"name":"tvm-1"}')],
    'a container' => [incusList(incusInstance(type: 'container'))],
    'frozen' => [incusList(incusInstance(status: 'Frozen'))],
    'listed twice' => [incusList(incusInstance(), incusInstance())],
    'the bridge address' => [incusList(incusInstance(addresses: [['address' => '10.251.77.1']]))],
    'the broadcast address' => [incusList(incusInstance(addresses: [['address' => '10.251.77.255']]))],
    'two bridge addresses' => [incusList(incusInstance(addresses: [['address' => '10.251.77.20'], ['address' => '10.251.77.21']]))],
]);

it('fails when incus list fails', function (): void {
    ($this->answer)(incusResult(exitCode: 1, stderr: 'Error: not authorized'));

    expect(fn () => $this->provider->observe($this->vm))->toThrow(TaskVmException::class, '`incus list` for task VM [tvm-1] failed with exit code [1].');
});

it('reads the cloud-init status', function (CommandResult $result, bool $ready): void {
    ($this->answer)($result);

    expect($this->guest->bootstrapReady($this->vm))->toBe($ready)
        ->and(($this->argv)())->toBe([[...INCUS_PREFIX, 'exec', '--', 'tvm-1', 'timeout', '30', 'cloud-init', 'status', '--wait', '--format=json']]);
})->with([
    'agent not running' => [incusResult(exitCode: 1, stderr: "Error: VM agent isn't currently running"), false],
    'still running after the wait' => [incusResult(exitCode: 124), false],
    'running' => [incusResult('{"status":"running","errors":[]}'), false],
    'done' => [incusResult('{"status":"done","extended_status":"done","errors":[]}'), true],
    'done with warnings' => [incusResult('{"status":"done","errors":[],"recoverable_errors":{"WARNING":["schema"]}}', 2), true],
]);

it('fails when cloud-init failed', function (): void {
    ($this->answer)(incusResult('{"status":"error","errors":["apt failed"]}', 1));

    expect(fn () => $this->guest->bootstrapReady($this->vm))->toThrow(TaskVmException::class, 'Cloud-init failed on task VM [tvm-1]: ["apt failed"]');
});

it('refuses an invalid cloud-init status', function (string $stdout): void {
    ($this->answer)(incusResult($stdout));

    expect(fn () => $this->guest->bootstrapReady($this->vm))->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->getMessage()])->toBe(['task_vm.invalid_host_output', 'Task VM [tvm-1] returned invalid guest output: '.($stdout === 'done' ? 'the cloud-init status is not complete JSON.' : 'the cloud-init status has no status or errors.')]));
})->with(['done', '{"status":"done"}', '{"status":"done","errors":{"a":1}}', '{"errors":[]}']);

it('reads the ed25519 host key fingerprint', function (): void {
    $fingerprint = 'SHA256:'.str_repeat('Ab3+/', 8).'xyz';
    ($this->answer)(incusResult("256 {$fingerprint} root@tvm-1 (ED25519)\n"));

    expect($this->provider->sshHostFingerprint($this->vm))->toBe($fingerprint)
        ->and(($this->argv)())->toBe([[...INCUS_PREFIX, 'exec', '--', 'tvm-1', 'ssh-keygen', '-l', '-f', '/etc/ssh/ssh_host_ed25519_key.pub']]);
});

it('reads the image manifest, or null for an image without one', function (string $stdout, ?string $manifest): void {
    ($this->answer)(incusResult($stdout));

    expect($this->guest->imageManifest($this->vm))->toBe($manifest)
        ->and(($this->argv)())->toBe([[...INCUS_PREFIX, 'exec', '--', 'tvm-1', 'sh', '-c', 'if [ -e "$1" ]; then cat -- "$1"; else printf absent; fi', 'sh', '/var/lib/orbit-task-vm/image.json']]);
})->with([
    'manifest' => ['{"schema":1,"programs":{}}'."\n", '{"schema":1,"programs":{}}'."\n"],
    'none' => ['absent', null],
]);

it('fails when it cannot read the image manifest', function (): void {
    ($this->answer)(incusResult(exitCode: 1, stderr: "Error: VM agent isn't currently running"));

    expect(fn () => $this->guest->imageManifest($this->vm))->toThrow(fn (TaskVmException $e) => expect([$e->errorCode, $e->getMessage()])->toBe(['task_vm.host_command_failed', "On task VM [tvm-1], the image manifest read failed with exit code [1].\nError: VM agent isn't currently running"]));
});

it('refuses an invalid fingerprint', function (CommandResult $result, string $code): void {
    ($this->answer)($result);

    expect(fn () => $this->provider->sshHostFingerprint($this->vm))->toThrow(fn (TaskVmException $e) => expect($e->errorCode)->toBe($code));
})->with([
    'rsa' => [incusResult('3072 SHA256:'.str_repeat('a', 43)." root@tvm-1 (RSA)\n"), 'task_vm.invalid_host_output'],
    'short' => [incusResult("256 SHA256:abc root@tvm-1 (ED25519)\n"), 'task_vm.invalid_host_output'],
    'two lines' => [incusResult(str_repeat('256 SHA256:'.str_repeat('a', 43)." root@tvm-1 (ED25519)\n", 2)), 'task_vm.invalid_host_output'],
    'no key' => [incusResult(exitCode: 1, stderr: 'No such file'), 'task_vm.host_command_failed'],
]);

it('deletes the VM and its workspace clone, and accepts an absent one', function (): void {
    ($this->answer)(incusResult(), incusResult());
    $this->provider->destroy($this->vm);
    expect(($this->argv)())->toBe([[...INCUS_PREFIX, 'delete', '--force', '--', 'tvm-1'], [...INCUS_PREFIX, 'storage', 'volume', 'delete', '--', 'tank', 'tvm-1-workspace']]);

    ($this->answer)(incusResult(exitCode: 1, stderr: 'Error: Instance not found'), incusList(), incusResult(exitCode: 1, stderr: 'Error: Storage volume not found'), incusResult('[]'));
    $this->provider->destroy($this->vm);

    ($this->answer)(incusResult(exitCode: 1, stderr: 'Error: busy'), incusList(incusInstance()));
    expect(fn () => $this->provider->destroy($this->vm))->toThrow(TaskVmException::class, '`incus delete` for task VM [tvm-1] failed with exit code [1].');
});

describe('park and resume', function (): void {
    it('stops a running VM with a clean shutdown and keeps it', function (): void {
        ($this->answer)(incusList(incusInstance()), incusResult());

        $this->provider->stop($this->vm);

        expect(($this->argv)())->toBe([
            [...INCUS_PREFIX, 'list', '--format', 'json', '--', 'tvm-1'],
            [...INCUS_PREFIX, 'stop', '--timeout', '120', '--', 'tvm-1'],
        ]);
    });

    it('stops with force when the guest does not shut down in time', function (): void {
        ($this->answer)(incusList(incusInstance()), incusResult(exitCode: 1, stderr: 'timed out'), incusList(incusInstance()), incusResult());

        $this->provider->stop($this->vm);

        expect(($this->argv)()[3])->toBe([...INCUS_PREFIX, 'stop', '--force', '--', 'tvm-1']);
    });

    it('fails when the VM still runs after a forced stop', function (): void {
        ($this->answer)(incusList(incusInstance()), incusResult(exitCode: 1), incusList(incusInstance()), incusResult(exitCode: 1, stderr: 'busy'), incusList(incusInstance()));

        expect(fn () => $this->provider->stop($this->vm))->toThrow(TaskVmException::class, '`incus stop` for task VM [tvm-1] failed');
    });

    it('counts a stopped or absent VM as stopped', function (): void {
        ($this->answer)(incusList(incusInstance(status: 'Stopped')), incusList());

        $this->provider->stop($this->vm);
        $this->provider->stop($this->vm);

        expect($this->ssh->calls)->toHaveCount(2);
    });

    it('starts a stopped VM and leaves a running one alone', function (): void {
        ($this->answer)(incusList(incusInstance(status: 'Stopped')), incusResult(), incusList(incusInstance()));

        $this->provider->start($this->vm);
        $this->provider->start($this->vm);

        expect(($this->argv)())->toBe([
            [...INCUS_PREFIX, 'list', '--format', 'json', '--', 'tvm-1'],
            [...INCUS_PREFIX, 'start', '--', 'tvm-1'],
            [...INCUS_PREFIX, 'list', '--format', 'json', '--', 'tvm-1'],
        ]);
    });

    it('refuses to start a VM that is gone', function (): void {
        ($this->answer)(incusList());

        expect(fn () => $this->provider->start($this->vm))->toThrow(TaskVmException::class, 'Task VM [tvm-1] is gone from its host.');
    });
});
