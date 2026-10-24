<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\IncusTaskVmBaseImage;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Jobs\TaskVms\ImageBuild\BuildRecipeImageStep;
use App\Jobs\TaskVms\SweepTaskVmHosts;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\TaskVmSettingsBuilder;

use function Pest\Laravel\mock;

/** Answers `incus list` with the scripted instances and deletes from them, per host address. */
final class TaskVmSweepIncus implements SshExecutor
{
    /** @var array<string, list<string>> */
    public array $instances = [];

    /** @var list<array{string, list<string>}> */
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

    public function bindAgentInventory(): void
    {
        $agents = new FakeAgentCommandRunner;
        $agents->answer('incus.instances', function (array $input, Node $node): array {
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

            return ['instances' => $instances, 'volumes' => $volumes];
        });
        $agents->answer('incus.volumes', ['names' => []]);
        app()->instance(AgentCommandRunner::class, $agents);
    }

    public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        if ($this->beforeExecute !== null) {
            ($this->beforeExecute)($command);
        }
        $result = $this->executeCommand($connection, $command);

        return $result;
    }

    private function executeCommand(SshConnection $connection, RemoteCommand $command): CommandResult
    {
        $this->calls[] = [$connection->host, $command->arguments];
        $arguments = array_slice($command->arguments, 5);
        if ($arguments === ['list', '--format', 'json']) {
            return new CommandResult(0, json_encode(array_map(static fn (string $name): array => ['name' => $name, 'config' => []], $this->instances[$connection->host] ?? []), JSON_THROW_ON_ERROR), '', 1, false);
        }
        if (($arguments[0] ?? null) === 'delete') {
            $name = end($arguments);
            $this->instances[$connection->host] = array_values(array_diff($this->instances[$connection->host] ?? [], [$name]));

            return new CommandResult(0, '', '', 1, false);
        }

        throw new RuntimeException('Unexpected command: '.implode(' ', $command->arguments));
    }
}

function tvm_sweep_node(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'nckrtl', 'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
}

function tvm_sweep_vm(Node $host, TaskVmState $state, string $wireguardIp, TaskOrchestration $orchestration = TaskOrchestration::Tick): TaskVm
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
    app()->instance(SshExecutor::class, $this->incus);
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

describe('image build ownership', function (): void {
    it('protects running builds and retains failed builders until the retention window expires', function (): void {
        $running = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id]);
        $failed = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id, 'state' => 'failed']);
        $succeeded = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id, 'state' => 'succeeded']);
        $this->travel(25)->hours();
        $this->incus->instances = ['10.44.0.7' => [$running->builder_vm_name, $running->builder_vm_name.'-smoke', $failed->builder_vm_name, $succeeded->builder_vm_name, 'tvm-image-99999'], '10.44.0.8' => [$running->builder_vm_name]];
        $settings = app(TaskVmSettings::class);
        $audit = app(TaskVmHostAudit::class);
        expect($audit->strays($settings->host($this->beast->id), $this->beast)['instances'])->toBe(['orbit-tasks/'.$failed->builder_vm_name, 'orbit-tasks/'.$succeeded->builder_vm_name, 'orbit-tasks/tvm-image-99999'])
            ->and($audit->strays($settings->host($this->shark->id), $this->shark)['instances'])->toBe(['orbit-tasks/'.$running->builder_vm_name]);
        app()->call([new SweepTaskVmHosts, 'handle']);
        expect($this->incus->instances['10.44.0.7'])->toBe([$running->builder_vm_name, $running->builder_vm_name.'-smoke'])
            ->and($this->incus->instances['10.44.0.8'])->toBe([]);
    });

    it('protects running builders and smoke VMs from the base image sweep used by sync', function (): void {
        $running = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id]);
        $failed = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id, 'state' => 'failed']);
        $this->incus->instances = ['10.44.0.7' => [$running->builder_vm_name, $running->builder_vm_name.'-smoke', $failed->builder_vm_name, 'tvm-image-20261013133000-sync-smoke']];
        app(IncusTaskVmBaseImage::class)->sweep(app(TaskVmSettings::class)->host($this->beast->id), $this->beast);
        expect($this->incus->instances['10.44.0.7'])->toBe([$running->builder_vm_name, $running->builder_vm_name.'-smoke', $failed->builder_vm_name]);
        $this->travel(25)->hours();
        app(IncusTaskVmBaseImage::class)->sweep(app(TaskVmSettings::class)->host($this->beast->id), $this->beast);
        expect($this->incus->instances['10.44.0.7'])->toBe([$running->builder_vm_name, $running->builder_vm_name.'-smoke']);
    });
});

describe(SweepTaskVmHosts::class, function (): void {
    it('deletes only tvm-<number> VMs without a live row, and only in each host project', function (): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        $provisioning = tvm_sweep_vm($this->shark, TaskVmState::Provisioning, '10.44.0.130');
        $destroyed = tvm_sweep_vm($this->beast, TaskVmState::Destroyed, '10.44.0.131');
        $this->incus->instances = [
            '10.44.0.7' => [$ready->name, $destroyed->name, 'tvm-9999', 'tvm-image-20261010030000-sync-smoke', 'dev-box', $provisioning->name],
            '10.44.0.8' => [$provisioning->name, 'tvm-8888'],
        ];

        app()->call([new SweepTaskVmHosts, 'handle']);

        // A VM recorded on another host is foreign to this one, so beast deletes it as an orphan too.
        expect($this->incus->instances)->toBe([
            '10.44.0.7' => [$ready->name, 'tvm-image-20261010030000-sync-smoke', 'dev-box'],
            '10.44.0.8' => [$provisioning->name],
        ])
            ->and(collect($this->incus->calls)->every(static fn (array $call): bool => array_slice($call[1], 0, 5) === ['sudo', '-n', 'incus', '--project', 'orbit-tasks']
                || (array_slice($call[1], 0, 5) === ['sudo', '-n', 'incus', 'query', '--'] && count($call[1]) === 6)))->toBeTrue()
            ->and($ready->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('records a missing VM after two sweeps and asks only tick groups for assistance', function (TaskOrchestration $orchestration, bool $assistance): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129', $orchestration);
        $parked = tvm_sweep_vm($this->beast, TaskVmState::Parked, '10.44.0.130');
        $provisioning = tvm_sweep_vm($this->beast, TaskVmState::Provisioning, '10.44.0.131');
        $destroying = tvm_sweep_vm($this->beast, TaskVmState::Destroying, '10.44.0.132');
        $this->incus->instances = ['10.44.0.7' => [$parked->name]];

        app()->call([new SweepTaskVmHosts, 'handle']);
        expect($ready->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and($ready->group->fresh()?->assistance_requested)->toBeFalse();

        app()->call([new SweepTaskVmHosts, 'handle']);

        expect($ready->fresh())->state->toBe(TaskVmState::Failed)->error_code->toBe('task_vm.vm_missing')
            ->error_message->toBe('Task VM ['.$ready->name.'] is gone from its host.')
            ->and($ready->group->fresh())->assistance_requested->toBe($assistance)
            ->assistance_reason->toBe($assistance ? 'Task VM failed: task_vm.vm_missing: Task VM ['.$ready->name.'] is gone from its host. Cancel or complete the group to destroy the VM.' : null)
            ->and($parked->fresh()?->state)->toBe(TaskVmState::Parked)
            ->and($provisioning->fresh()?->state)->toBe(TaskVmState::Provisioning)
            ->and($destroying->fresh()?->state)->toBe(TaskVmState::Destroying);
    })->with(['tick' => [TaskOrchestration::Tick, true], 'external' => [TaskOrchestration::External, false]]);

    it('forgets a miss when the next sweep finds the VM again', function (): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');

        app()->call([new SweepTaskVmHosts, 'handle']);
        $this->incus->instances = ['10.44.0.7' => [$ready->name]];
        app()->call([new SweepTaskVmHosts, 'handle']);
        $this->incus->instances = [];
        app()->call([new SweepTaskVmHosts, 'handle']);

        expect($ready->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('fails closed and changes nothing when the host project does not exist', function (): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        $parked = tvm_sweep_vm($this->beast, TaskVmState::Parked, '10.44.0.130');
        $this->incus->projectMissing = true;

        foreach ([1, 2, 3] as $sweep) {
            expect(fn () => app()->call([new SweepTaskVmHosts, 'handle']))
                ->toThrow(TaskVmException::class, 'Node [beast]: task_vm.host_command_failed');
        }

        expect($ready->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and($parked->fresh()?->state)->toBe(TaskVmState::Parked)
            ->and(collect($this->incus->calls)->contains(static fn (array $call): bool => in_array('delete', $call[1], true)))->toBeFalse();
    });

    it('sweeps the other hosts when one fails, then fails', function (): void {
        $this->incus->failList = true;

        expect(fn () => app()->call([new SweepTaskVmHosts, 'handle']))
            ->toThrow(TaskVmException::class, 'The task VM host sweep failed on Node [beast]: task_vm.host_command_failed, Node [shark]: task_vm.host_command_failed.');
    });

    it('skips a host that is not an active Node', function (): void {
        $this->shark->update(['status' => LifecycleStatus::Failed]);
        $this->incus->instances = ['10.44.0.8' => ['tvm-8888']];

        app()->call([new SweepTaskVmHosts, 'handle']);

        expect($this->incus->instances['10.44.0.8'])->toBe(['tvm-8888']);
    });

    it('is queued on the task VM queue every 10 minutes while task VMs are enabled or the UpCloud token file is set', function (): void {
        Artisan::call('schedule:list');
        $sweep = collect(app(Schedule::class)->events())->first(static fn ($event): bool => $event instanceof CallbackEvent && $event->description === SweepTaskVmHosts::class);

        config(['task_vms.enabled' => false, 'task_vms.upcloud.token_file' => null]);
        expect($sweep)->not->toBeNull()
            ->and($sweep->expression)->toBe('*/10 * * * *')
            ->and($sweep->filtersPass(app()))->toBeFalse()
            ->and([new SweepTaskVmHosts()->connection, new SweepTaskVmHosts()->queue])->toBe(['gateway', 'task-vms']);

        config(['task_vms.enabled' => true]);
        expect($sweep->filtersPass(app()))->toBeTrue();

        config(['task_vms.enabled' => false, 'task_vms.upcloud.token_file' => '/home/orbit/upcloud.yaml']);
        expect($sweep->filtersPass(app()))->toBeTrue();
    });

    it('leaves the Incus hosts alone while task VMs are off', function (): void {
        app()->instance(TaskVmSettings::class, TaskVmSettingsBuilder::off(['hosts' => app(TaskVmSettings::class)->hosts]));
        $this->incus->instances = ['10.44.0.7' => ['tvm-9999']];

        app()->call([new SweepTaskVmHosts, 'handle']);

        expect($this->incus->instances['10.44.0.7'])->toBe(['tvm-9999'])
            ->and($this->incus->calls)->toBe([]);
    });
});

describe('Doctor', function (): void {
    /** @return list<array{string, string}> code and observed value of the task VM issues */
    function tvm_sweep_doctor(Node $node): array
    {
        $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData(true, 'linux', 'x86_64', true)));

        return array_values(array_map(
            static fn ($issue): array => [$issue->code, (string) $issue->observed],
            array_filter($report->issues, static fn ($issue): bool => str_starts_with($issue->code, 'node.task_vm_')),
        ));
    }

    it('reports orphaned and missing task VMs on the host without changing them', function (): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        $this->incus->instances = ['10.44.0.7' => ['tvm-9999', 'tvm-image-20261010030000-sync-smoke']];

        // Sync smoke still uses the time rule. Doctor reports an old one without changing it.
        expect(tvm_sweep_doctor($this->beast))->toBe([['node.task_vm_orphaned', 'tvm-9999'], ['node.task_vm_missing', 'absent'], ['node.task_vm_stray_instance', 'orbit-tasks/tvm-image-20261010030000-sync-smoke']])
            ->and($this->incus->instances['10.44.0.7'])->toBe(['tvm-9999', 'tvm-image-20261010030000-sync-smoke'])
            ->and($ready->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('reports a missing host project as unverifiable, not every VM as missing', function (): void {
        tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        $this->incus->projectMissing = true;

        expect(tvm_sweep_doctor($this->beast))->toBe([['node.task_vm_inspection_failed', 'task_vm.host_command_failed']]);
    });

    it('does not inspect the Node of a parked task VM and reports it as informational only', function (): void {
        $vm = tvm_sweep_vm($this->beast, TaskVmState::Parked, '10.44.0.129');
        $node = tvm_sweep_node('tvm-node', '10.44.0.129');
        $vm->update(['node_id' => $node->id]);
        mock(NodeStateInspector::class)->shouldReceive('inspect')->never();

        $report = app(RunDoctorAction::class)->executeForNode($node);
        $issues = collect($report->nodes[0]->families)->flatMap(static fn ($family): array => $family->issues);

        expect($issues->map(static fn ($issue): array => [$issue->code, $issue->kind])->all())
            ->toBe([['node.task_vm_parked', DoctorIssueKind::Informational]]);

        $vm->update(['state' => TaskVmState::Ready]);
        mock(NodeStateInspector::class)->shouldReceive('inspect')->once()->andReturn(new NodeInspectionData(false, null, null, null));
        $codes = collect(app(RunDoctorAction::class)->executeForNode($node)->nodes[0]->families)->flatMap(static fn ($family): array => $family->issues)->pluck('code');
        expect($codes)->toContain('node.ssh_unreachable');
    });

    it('reports an unlisted host as unverifiable', function (): void {
        $this->incus->failList = true;

        expect(tvm_sweep_doctor($this->beast))->toBe([['node.task_vm_inspection_failed', 'task_vm.host_command_failed']]);
    });

    it('checks no Node that is not a configured host, and nothing while task VMs are not configured', function (): void {
        $other = tvm_sweep_node('services', '10.44.0.3');
        expect(tvm_sweep_doctor($other))->toBe([]);

        app()->instance(TaskVmSettings::class, TaskVmSettingsBuilder::off());
        expect(tvm_sweep_doctor($this->beast))->toBe([])
            ->and($this->incus->calls)->toBe([]);
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
    function tvm_stray_doctor(Node $node, bool $reachable = true): array
    {
        $report = app(NodeDoctorProbe::class)->inspect(new DoctorNodeContext($node, new NodeInspectionData($reachable, 'linux', 'x86_64', true)));

        return array_values(array_map(
            static fn ($issue): array => [$issue->code, (string) $issue->observed],
            array_filter($report->issues, static fn ($issue): bool => str_starts_with($issue->code, 'node.task_vm_')),
        ));
    }

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

    it('reports instances and custom volumes that Orbit does not own, on every project and pool, and deletes none', function (): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        $this->incus->instances = ['10.44.0.7' => [$ready->name, 'tvm-image-20261013133000-sync-smoke', 'tvm-template-20261013120000', 'dev-box']];
        $this->incus->foreign = ['10.44.0.7' => ['orbit-ci/ci-beast-1', 'default/ow-exp-20261006', 'default/renewed-ghost', 'orbit-templates/orbit-template-agent-base']];
        $this->incus->images = ['10.44.0.7' => [str_repeat('a', 64)]];
        $this->incus->volumes = ['10.44.0.7' => [
            'orbit-tasks/orbit-tasks/tvm-template-46-20261013083819',
            'orbit-tasks/orbit-tasks/'.$ready->name.'-topology-seed', 'orbit-tasks/orbit-tasks/orbit-topology-seed-aaaaaaaaaaaa',
            'orbit-tasks/orbit-tasks/orbit-topology-seed-bbbbbbbbbbbb', 'orbit-tasks/default/orbit-topology-seed-aaaaaaaaaaaa',
            'orbit-tasks/orbit-tasks/scratch', 'orbit-tasks/default/tvm-template-46-20261013083819', 'orbit-ci/default/runner-cache',
        ]];
        Log::spy();

        app()->call([new SweepTaskVmHosts, 'handle']);

        $expected = [
            ['node.task_vm_stray_instance', 'orbit-tasks/tvm-image-20261013133000-sync-smoke'],
            ['node.task_vm_stray_instance', 'orbit-tasks/tvm-template-20261013120000'],
            ['node.task_vm_stray_instance', 'orbit-tasks/dev-box'],
            ['node.task_vm_stray_instance', 'default/renewed-ghost'],
            ['node.task_vm_stray_instance', 'orbit-templates/orbit-template-agent-base'],
            ['node.task_vm_stray_volume', 'orbit-tasks/default/orbit-topology-seed-aaaaaaaaaaaa'],
            ['node.task_vm_stray_volume', 'orbit-tasks/default/tvm-template-46-20261013083819'],
            ['node.task_vm_stray_volume', 'orbit-tasks/orbit-tasks/tvm-template-46-20261013083819'],
            ['node.task_vm_stray_volume', 'orbit-tasks/orbit-tasks/orbit-topology-seed-bbbbbbbbbbbb'],
            ['node.task_vm_stray_volume', 'orbit-tasks/orbit-tasks/scratch'],
        ];
        foreach ($expected as [$code, $stray]) {
            $instance = $code === 'node.task_vm_stray_instance';
            Log::shouldHaveReceived('warning')->with(
                $instance ? 'A task VM host has an Incus instance that Orbit does not own.' : 'A task VM host has an Incus custom volume that Orbit does not own.',
                ['node_id' => $this->beast->id, $instance ? 'instance' : 'volume' => $stray],
            )->once();
        }
        expect(collect($this->incus->calls)->contains(static fn (array $call): bool => in_array('delete', $call[1], true)))->toBeFalse()
            ->and(tvm_stray_doctor($this->beast))->toBe($expected);
    });

    it('reports ready and parked rows without an open group after an hour, including failed groups', function (): void {
        $noGroup = tvm_stray_vm($this->beast, TaskVmState::Ready, null, 61, '10.44.0.129');
        $completed = tvm_stray_vm($this->beast, TaskVmState::Parked, TaskGroupStatus::Completed, 90, '10.44.0.130');
        tvm_stray_vm($this->beast, TaskVmState::Ready, TaskGroupStatus::Cancelled, 30, '10.44.0.131');
        $failed = tvm_stray_vm($this->beast, TaskVmState::Ready, TaskGroupStatus::Failed, 600, '10.44.0.132');
        tvm_stray_vm($this->beast, TaskVmState::Parked, TaskGroupStatus::WaitingForReview, 600, '10.44.0.133');
        $this->incus->instances = ['10.44.0.7' => TaskVm::query()->pluck('name')->all()];
        Log::spy();

        app()->call([new SweepTaskVmHosts, 'handle']);

        expect(tvm_stray_doctor($this->beast))->toBe([
            ['node.task_vm_stray_record', $noGroup->name.' ready'],
            ['node.task_vm_stray_record', $completed->name.' parked'],
            ['node.task_vm_stray_record', $failed->name.' ready'],
        ]);
        Log::shouldHaveReceived('warning')->with('A task VM is ready or parked without an open group.', ['task_vm_id' => $noGroup->id, 'name' => $noGroup->name, 'state' => 'ready', 'group_id' => null])->once();
        Log::shouldHaveReceived('warning')->with('A task VM is ready or parked without an open group.', ['task_vm_id' => $completed->id, 'name' => $completed->name, 'state' => 'parked', 'group_id' => $completed->group_id])->once();
        expect($noGroup->fresh()?->state)->toBe(TaskVmState::Ready)
            ->and($completed->fresh()?->state)->toBe(TaskVmState::Parked);
    });

    it('reports a tvm Node without a live record after 15 minutes, also while it is unreachable', function (): void {
        $stray = tvm_sweep_node('tvm-41', '10.44.0.141');
        $destroyed = tvm_sweep_vm($this->beast, TaskVmState::Destroyed, '10.44.0.141');
        $destroyed->update(['node_id' => $stray->id]);
        $live = tvm_sweep_node('tvm-42', '10.44.0.142');
        tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.142')->update(['node_id' => $live->id]);
        $named = tvm_sweep_node('tvm-template-box', '10.44.0.143');
        $this->travel(16)->minutes();
        $young = tvm_sweep_node('tvm-43', '10.44.0.144');
        $this->incus->instances = ['10.44.0.7' => TaskVm::query()->live()->pluck('name')->all()];
        Log::spy();

        app()->call([new SweepTaskVmHosts, 'handle']);

        Log::shouldHaveReceived('warning')->with('A task VM Node has no live task VM record.', ['node_id' => $stray->id, 'name' => 'tvm-41'])->once();
        Log::shouldNotHaveReceived('warning', ['A task VM Node has no live task VM record.', ['node_id' => $young->id, 'name' => 'tvm-43']]);
        expect(tvm_stray_doctor($stray, reachable: false))->toBe([['node.task_vm_stray_node', 'none']])
            ->and(tvm_stray_doctor($live))->toBe([])
            ->and(tvm_stray_doctor($named))->toBe([])
            ->and(tvm_stray_doctor($young))->toBe([])
            ->and(Node::query()->whereKey($stray->id)->exists())->toBeTrue();
    });

    it('fails the host in the sweep and reports it as unverifiable in Doctor when the inventory cannot be read', function (): void {
        $this->incus->failInventory = true;

        expect(fn () => app()->call([new SweepTaskVmHosts, 'handle']))
            ->toThrow(TaskVmException::class, 'Node [beast]: task_vm.host_command_failed')
            ->and(tvm_stray_doctor($this->beast))->toBe([['node.task_vm_inspection_failed', 'task_vm.host_command_failed']]);
    });
});

it('fences resume when sweep deletion wins while the builder still exists', function (string $path, string $suffix): void {
    Bus::fake();
    $this->travel(-25)->hours();
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => 'sha256:'.str_repeat('a', 64), 'generation' => 1]);
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id, 'state' => 'failed',
        'recipe_identity' => 'sha256:'.str_repeat('a', 64), 'recipe_generation' => 1,
        'current_step' => TaskVmImageBuildStep::Recipe, 'current_program' => 'base:packages']);
    $name = $build->builder_vm_name.$suffix;
    $this->travel(25)->hours();
    // Smoke first, so the builder is still present when a smoke deletion races resume.
    $this->incus->instances['10.44.0.7'] = array_values(array_unique([$name, $build->builder_vm_name]));
    $host = app(TaskVmSettings::class)->host($this->beast->id);
    $overlapped = false;
    $this->incus->beforeExecute = function (RemoteCommand $command) use ($build, $name, &$overlapped): void {
        if (($command->arguments[5] ?? null) !== 'delete' || $command->arguments[array_key_last($command->arguments)] !== $name) {
            return;
        }
        $this->incus->beforeExecute = null;
        $overlapped = true;
        expect($this->incus->instances['10.44.0.7'])->toContain($build->builder_vm_name, $name);
        expect(fn () => app(TaskVmImageBuildChain::class)->resume($this->beast, $build->id))
            ->toThrow(TaskVmException::class, 'unclaimed failed build');
        expect($build->refresh()->cleanup_started_at)->not->toBeNull();
    };
    if ($path === 'base-image') {
        app(IncusTaskVmBaseImage::class)->sweep($host, $this->beast);
    } else {
        app(TaskVmHostAudit::class)->deleteOrphan($host, $this->beast, $name);
    }
    expect($overlapped)->toBeTrue()->and($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($this->incus->instances['10.44.0.7'])->not->toContain($name);
    Bus::assertNothingDispatched();
})->with(['base-image', 'host-audit'])->with(['builder' => '', 'smoke' => '-smoke']);

it('skips a stale sweep candidate when resume wins before deletion claims cleanup', function (string $path, string $suffix): void {
    Bus::fake();
    $this->travel(-25)->hours();
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => 'sha256:'.str_repeat('a', 64), 'generation' => 1]);
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id, 'state' => 'failed',
        'recipe_identity' => 'sha256:'.str_repeat('a', 64), 'recipe_generation' => 1,
        'current_step' => TaskVmImageBuildStep::Recipe, 'current_program' => 'base:packages']);
    $name = $build->builder_vm_name.$suffix;
    $this->incus->instances['10.44.0.7'] = array_values(array_unique([$name, $build->builder_vm_name]));
    $host = app(TaskVmSettings::class)->host($this->beast->id);
    $this->travel(25)->hours();
    $overlapped = false;
    if ($path === 'base-image') {
        // The sweep captures an inventory containing the failed build, then resume wins before its claim.
        $this->incus->afterInstances = function () use ($build, &$overlapped): void {
            $this->incus->afterInstances = null;
            $overlapped = true;
            app(TaskVmImageBuildChain::class)->resume($this->beast, $build->id);
        };
        app(IncusTaskVmBaseImage::class)->sweep($host, $this->beast);
    } else {
        $audit = app(TaskVmHostAudit::class);
        $candidates = $audit->audit($host, $this->beast)['orphaned'];
        expect($candidates)->toContain($name);
        app(TaskVmImageBuildChain::class)->resume($this->beast, $build->id);
        $overlapped = true;
        foreach ($candidates as $candidate) {
            $audit->deleteOrphan($host, $this->beast, $candidate);
        }
    }
    expect($overlapped)->toBeTrue()->and($build->refresh()->state)->toBe(TaskVmImageBuildState::Running)
        ->and($build->cleanup_started_at)->toBeNull()
        ->and($this->incus->instances['10.44.0.7'])->toContain($name, $build->builder_vm_name)
        ->and(array_any($this->incus->calls, static fn (array $call): bool => ($call[1][5] ?? null) === 'delete'))->toBeFalse();
    Bus::assertDispatched(BuildRecipeImageStep::class, fn (BuildRecipeImageStep $job): bool => $job->buildId === $build->id);
})->with(['base-image', 'host-audit'])->with(['builder' => '', 'smoke' => '-smoke']);

it('rejects a stale resume when a sweep wins after the builder existence result is captured', function (string $path, string $suffix): void {
    Bus::fake();
    $this->travel(-25)->hours();
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => 'sha256:'.str_repeat('a', 64), 'generation' => 1]);
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id, 'state' => 'failed',
        'recipe_identity' => 'sha256:'.str_repeat('a', 64), 'recipe_generation' => 1,
        'current_step' => TaskVmImageBuildStep::Recipe, 'current_program' => 'base:packages']);
    $name = $build->builder_vm_name.$suffix;
    $this->incus->instances['10.44.0.7'] = array_values(array_unique([$name, $build->builder_vm_name]));
    $host = app(TaskVmSettings::class)->host($this->beast->id);
    $overlapped = false;
    $this->travel(25)->hours();
    $this->incus->afterInstances = function () use ($host, $name, $path, &$overlapped): void {
        // Resume now holds the old existence result. Sweep claims the row before resume's conditional write.
        $this->incus->afterInstances = null;
        $overlapped = true;
        if ($path === 'base-image') {
            app(IncusTaskVmBaseImage::class)->sweep($host, $this->beast);
        } else {
            app(TaskVmHostAudit::class)->deleteOrphan($host, $this->beast, $name);
        }
    };
    expect(fn () => app(TaskVmImageBuildChain::class)->resume($this->beast, $build->id))
        ->toThrow(TaskVmException::class, 'already claimed');
    expect($overlapped)->toBeTrue()->and($build->refresh()->state)->toBe(TaskVmImageBuildState::Failed)
        ->and($build->cleanup_started_at)->not->toBeNull()
        ->and($this->incus->instances['10.44.0.7'])->not->toContain($name);
    Bus::assertNothingDispatched();
})->with(['base-image', 'host-audit'])->with(['builder' => '', 'smoke' => '-smoke']);
