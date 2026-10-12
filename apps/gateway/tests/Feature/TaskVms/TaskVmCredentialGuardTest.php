<?php

declare(strict_types=1);

use App\Domain\GitHub\GitReadEnvironment;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\NativeSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusHost;
use App\Infrastructure\TaskVms\TaskVmCredentialGuard;
use App\Models\Node;
use App\Models\TaskVm;

final class TaskVmGuardRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $runs = [];

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->runs[] = $invocation;

        return new CommandResult(0, '', '', 1, false);
    }
}

function tvm_guard_vm(TaskVmState $state = TaskVmState::Ready): TaskVm
{
    $host = Node::query()->create(['name' => 'beast', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.7', 'wireguard_ip' => '10.44.0.7', 'user' => 'nckrtl']);

    return TaskVm::query()->create([
        'host_node_id' => $host->id, 'provider' => 'incus', 'name' => 'tvm-1', 'state' => $state,
        'wireguard_ip' => '10.44.0.130', 'address' => '10.251.77.20', 'pi_token' => str_repeat('p', 64),
    ]);
}

function tvm_guard_credential(): ProtectedInput
{
    $script = GitReadScript::for(GitReadEnvironment::forGitHubToken('ghs_guarded'), 'git_read git ls-remote -- "$1"');

    return $script->protectedInput ?? throw new LogicException('A token-bearing read has protected input.');
}

function tvm_guard_connection(string $host): SshConnection
{
    return new SshConnection($host, 'orbit', 22, '/keys/id', '/keys/known_hosts', shareConnection: false);
}

beforeEach(function (): void {
    $this->runner = new TaskVmGuardRunner;
    $this->guard = new TaskVmCredentialGuard(new NativeSshExecutor($this->runner));
});

it('is the Gateway SSH executor', function (): void {
    expect(app(SshExecutor::class))->toBeInstanceOf(TaskVmCredentialGuard::class);
});

it('refuses a command with a GitHub credential to a task VM, at its WireGuard or bridge address', function (string $host): void {
    tvm_guard_vm();

    expect(fn () => $this->guard->execute(tvm_guard_connection($host), new RemoteCommand(['bash', '-se'], protectedInput: tvm_guard_credential())))
        ->toThrow(TaskVmException::class, 'A command that carries a GitHub credential cannot run on a task VM.');
    expect($this->runner->runs)->toBe([]);
})->with(['WireGuard' => ['10.44.0.130'], 'bridge' => ['10.251.77.20']]);

it('passes other commands to a task VM, and a credential to other Nodes and to destroyed task VMs', function (): void {
    $vm = tvm_guard_vm();

    $this->guard->execute(tvm_guard_connection('10.44.0.130'), new RemoteCommand(['bash', '-se'], protectedInput: ProtectedInput::fromString('echo secret-but-not-git')));
    $this->guard->execute(tvm_guard_connection('10.44.0.9'), new RemoteCommand(['bash', '-se'], protectedInput: tvm_guard_credential()));
    $vm->update(['state' => TaskVmState::Destroyed]);
    $this->guard->execute(tvm_guard_connection('10.44.0.130'), new RemoteCommand(['bash', '-se'], protectedInput: tvm_guard_credential()));

    expect($this->runner->runs)->toHaveCount(3);
});

it('marks only token-bearing reads as a GitHub credential', function (): void {
    expect(GitReadScript::for(GitReadEnvironment::none(), 'git_read git ls-remote')->protectedInput)->toBeNull()
        ->and(tvm_guard_credential()->carriesGitCredential())->toBeTrue()
        ->and(ProtectedInput::fromString('x')->carriesGitCredential())->toBeFalse();
});

it('refuses a GitHub credential on a task VM host, where incus exec would hand it to a VM', function (): void {
    $transport = new TaskVmGuardRunner;
    $incus = new IncusHost(new TaskVmCredentialGuard(new NativeSshExecutor($transport)), new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA';
        }
    }, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
    $host = Node::query()->create(['name' => 'shark', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '10.44.0.8', 'wireguard_ip' => '10.44.0.8', 'user' => 'nckrtl']);
    $taskHost = new TaskVmHost($host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 4, 2, '4GiB', '40GiB');

    expect(fn () => $incus->run($taskHost, $host, ['exec', '--', 'tvm-template-1', 'bash', '-se'], tvm_guard_credential()))
        ->toThrow(TaskVmException::class, 'A command that carries a GitHub credential cannot run on a task VM.');
    expect($transport->runs)->toBe([]);
});
