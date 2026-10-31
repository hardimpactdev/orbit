<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\TaskVmSettingsBuilder;

/** Answers `incus list` with the scripted instances and deletes from them, per host address. */
final class TaskVmSweepIncus
{
    /** @var array<string, list<string>> */
    public array $instances = [];

    /** @var list<array{string, array<string, mixed>}> */
    public array $calls = [];

    public bool $failList = false;

    public bool $projectMissing = false;

    /** @var array<string, list<string>> instances outside the task VM project, as `project/name`, per host address */
    public array $foreign = [];

    /** @var array<string, list<string>> custom volumes as `project/pool/name`, per host address */
    public array $volumes = [];

    /** @var array<string, list<string>> image fingerprints of the task VM project, per host address */
    public array $images = [];

    public bool $failInventory = false;

    /** Deterministic overlap hooks around the remote operation, without mocking database arbitration. */
    public ?Closure $beforeExecute = null;

    public ?Closure $afterInstances = null;

    public ?Closure $afterVolumes = null;

    public ?Closure $afterInventory = null;

    /** @var list<array{command:string,input:array<string,mixed>}> */
    public array $reads = [];

    public function bindAgentInventory(): void
    {
        $agents = new FakeAgentCommandRunner;
        $agents->answer('incus.vm', function (array $input, Node $node): array {
            ($this->beforeExecute)?->__invoke($input);
            $this->calls[] = [$node->wireguard_ip, $input];
            if ($input['operation']['kind'] !== 'remove_instance') {
                throw new LogicException('Unexpected VM intent.');
            }
            $this->instances[$node->wireguard_ip] = array_values(array_diff($this->instances[$node->wireguard_ip] ?? [], [$input['vm']['name']]));

            return ['ready' => true];
        });
        $agents->answer('incus.instances', function (array $input, Node $node): array {
            $this->reads[] = ['command' => 'incus.instances', 'input' => $input];
            if ($this->projectMissing || $this->failList) {
                throw new AgentCommandRefused('incus.instances', 'task_vm.host_command_failed', 'Inventory unavailable.', ['operation' => $this->projectMissing ? 'project show' : 'list', 'exit_code' => 1]);
            }

            return ['names' => $this->instances[$node->wireguard_ip] ?? []];
        });
        $agents->answer('incus.instance-images', function (array $input, Node $node): array {
            if ($this->projectMissing || $this->failList) {
                throw new AgentCommandRefused('incus.instance-images', 'task_vm.host_command_failed', 'Inventory unavailable.', ['operation' => 'list', 'exit_code' => 1]);
            }
            $result = ['instances' => array_fill_keys($this->instances[$node->wireguard_ip] ?? [], null)];
            ($this->afterInstances)?->__invoke();

            return $result;
        });
        $agents->answer('incus.images', fn (array $input, Node $node): array => ['images' => array_fill_keys($this->images[$node->wireguard_ip] ?? [], [])]);
        $agents->answer('incus.inventory', function (array $input, Node $node): array {
            if ($this->failInventory) {
                throw new AgentCommandRefused('incus.inventory', 'task_vm.host_command_failed', 'Inventory unavailable.', ['operation' => 'query', 'exit_code' => 1]);
            }
            $instances = array_map(static fn (string $name): array => ['project' => 'orbit-tasks', 'name' => $name], $this->instances[$node->wireguard_ip] ?? []);
            foreach ($this->foreign[$node->wireguard_ip] ?? [] as $resource) {
                [$project, $name] = explode('/', $resource, 2);
                $instances[] = ['project' => $project, 'name' => $name];
            }
            $volumes = [];
            foreach ($this->volumes[$node->wireguard_ip] ?? [] as $resource) {
                [$project, $pool, $name] = explode('/', $resource, 3);
                $volumes[] = ['project' => $project, 'pool' => $pool, 'name' => $name];
            }
            usort($volumes, static fn (array $a, array $b): int => strcmp($a['pool'], $b['pool']));

            $result = ['instances' => $instances, 'volumes' => $volumes];
            ($this->afterInventory)?->__invoke();

            return $result;
        });
        $agents->answer('incus.volumes', function (array $input, Node $node): array {
            $this->reads[] = ['command' => 'incus.volumes', 'input' => $input];
            $prefix = $input['project'].'/'.$input['pool'].'/';
            $names = array_map(static fn (string $volume): string => substr($volume, strlen($prefix)),
                array_values(array_filter($this->volumes[$node->wireguard_ip] ?? [], static fn (string $volume): bool => str_starts_with($volume, $prefix))));
            ($this->afterVolumes)?->__invoke();

            return ['names' => $names];
        });
        $agents->answer('incus.volume', function (array $input, Node $node): array {
            $this->calls[] = [$node->wireguard_ip, $input];
            $name = $input['operation']['name'];
            $path = $input['location']['project'].'/'.$input['location']['pool'].'/'.$name;
            $this->volumes[$node->wireguard_ip] = array_values(array_diff($this->volumes[$node->wireguard_ip] ?? [], [$path]));

            return ['name' => $name];
        });
        app()->instance(AgentCommandRunner::class, $agents);
    }
}

function tvm_sweep_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'nckrtl', 'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
}

function tvm_sweep_vm(Node $host, TaskVmState $state, string $wireguardIp, TaskOrchestration $orchestration = TaskOrchestration::External): TaskVm
{
    $group = Task::topLevel()->create([
        'orchestration' => $orchestration, 'project_id' => test()->project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => TaskGroupStatus::WaitingForReview,
    ]);
    $vm = TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => $host->id, 'provider' => 'incus', 'name' => 'tvm-pending-'.$group->id,
        'state' => $state, 'wireguard_ip' => $wireguardIp, 'pi_token' => str_repeat('p', 64),
    ]);
    $vm->update(['name' => 'tvm-'.$vm->id]);

    return $vm;
}

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $this->beast = tvm_sweep_node('beast', '10.44.0.7');
    $this->shark = tvm_sweep_node('shark', '10.44.0.8');
    TaskVmSettingsBuilder::bind(['hosts' => [
        TaskVmSettingsBuilder::host($this->beast->id, ['maxVms' => 4]),
        TaskVmSettingsBuilder::host($this->shark->id, ['cidr' => '10.251.78.0/24', 'maxVms' => 4]),
    ]]);
    $this->incus = new TaskVmSweepIncus;
    $this->incus->bindAgentInventory();
    app()->instance(SshKeyProvider::class, new class implements SshKeyProvider
    {
        public function privateKeyPath(): string
        {
            return '/keys/id_ed25519';
        }

        public function publicKey(): string
        {
            return 'ssh-ed25519 AAAA gateway';
        }
    });
    app()->instance(KnownHostsStore::class, new class implements KnownHostsStore
    {
        public function path(): string
        {
            return '/keys/known_hosts';
        }

        public function put(string $host, int $port, HostKey $key): void {}
    });
});

describe('strays', function (): void {
    it('recognizes the CI role project without an operator-owned exception', function (): void {
        TaskVmSettingsBuilder::bind(['hosts' => [TaskVmSettingsBuilder::host($this->beast->id)]]);
        $this->incus->foreign = ['10.44.0.7' => ['orbit-ci/ci-beast-1', 'default/stray']];
        $this->incus->volumes = ['10.44.0.7' => ['orbit-ci/default/cache']];
        $this->beast->roles()->create(['role' => RoleName::CiRunner, 'status' => LifecycleStatus::Active]);
        $host = app(TaskVmSettings::class)->host($this->beast->id);
        $audit = app(TaskVmHostAudit::class);

        expect($audit->strays($host, $this->beast))->toMatchArray(['instances' => ['default/stray'], 'volumes' => []]);
        $this->beast->roles()->where('role', 'ci-runner')->delete();
        expect($audit->strays($host, $this->beast))->toMatchArray([
            'instances' => ['orbit-ci/ci-beast-1', 'default/stray'], 'volumes' => ['orbit-ci/default/cache'],
        ]);
    });

    /** @return list<array{string, string}> code and observed value of the stray issues */

    /** A live row of `$state` on `$host` whose group has `$status`, or no group, last changed `$minutes` ago. */
    function tvm_stray_vm(Node $host, TaskVmState $state, ?TaskGroupStatus $status, int $minutes, string $wireguardIp): TaskVm
    {
        $vm = tvm_sweep_vm($host, $state, $wireguardIp);
        $at = now()->subMinutes($minutes);
        if ($status === null) {
            TaskVm::query()->whereKey($vm->id)->update(['group_id' => null, 'updated_at' => $at]);
        } else {
            Task::query()->withoutGlobalScopes()->whereKey($vm->group_id)->update(['status' => $status->value, 'updated_at' => $at]);
            TaskVm::query()->whereKey($vm->id)->update(['updated_at' => $at]);
        }

        return $vm->fresh() ?? throw new RuntimeException('The row is gone.');
    }

    beforeEach(function (): void {
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(14, 0));
        TaskVmSettingsBuilder::bind(['hosts' => [
            TaskVmSettingsBuilder::host($this->beast->id, ['maxVms' => 4, 'operatorOwned' => ['orbit-ci/*', 'default/ow-exp-20261006']]),
        ]]);
    });

});
