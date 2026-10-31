<?php

declare(strict_types=1);

use App\Actions\Doctor\NodeDoctorProbe;
use App\Actions\Doctor\RunDoctorAction;
use App\Actions\TaskVms\StartTaskVmSweepOperationAction;
use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmFamilyChildren;
use App\Domain\TaskVms\TaskVmImageBuildState;
use App\Domain\TaskVms\TaskVmImageBuildStep;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmHostAudit;
use App\Infrastructure\TaskVms\TaskVmImageBuildChain;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAgentCommandRunner;
use Tests\Support\FakeRoutePublicationSocket;
use Tests\Support\TaskVmSettingsBuilder;

use function Pest\Laravel\mock;

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

});

/** Admit a parent on behalf of the configured Services peer, then use only its recorded child keys. */
function tvm_executor_sweep(Node $host): Operation
{
    $parent = app(StartTaskVmSweepOperationAction::class)->execute($host->id, 'incus');
    test()->postJson('/api/v1/operation-executor/claim', ['kind' => $parent->kind, 'operation_id' => $parent->id, 'worker' => 'sweep-parent'])
        ->assertOk()->assertJsonPath('data.id', $parent->id);

    return $parent;
}

function tvm_executor_sweep_child(Operation $parent, string $key): Operation
{
    test()->postJson('/api/v1/operation-executor/'.$parent->id.'/children', ['worker' => 'sweep-parent', 'key' => $key])->assertAccepted();
    $child = $parent->children()->where('child_key', $key)->sole();
    test()->postJson('/api/v1/operation-executor/claim', ['kind' => $child->kind, 'operation_id' => $child->id, 'worker' => 'sweep-child'])
        ->assertOk()->assertJsonPath('data.id', $child->id);

    return $child;
}

/** @return array<string,mixed> */
function tvm_executor_sweep_body(Operation $child, string $key = 'sweep-effect'): array
{
    $input = array_intersect_key($child->input, array_flip(['node_id', 'provider', 'sweep_id', 'deletion']));

    return ['worker' => 'sweep-child', 'key' => $key, 'transport' => 'task_vm_sweep', 'name' => 'task_vm.sweep',
        'input' => [...$input, 'action' => $child->kind === 'task_vm.sweep_observe' ? 'observe' : 'delete']];
}

function tvm_executor_sweep_run(Operation $child, string $key = 'sweep-effect'): void
{
    test()->postJson('/api/v1/operation-executor/'.$child->id.'/run', tvm_executor_sweep_body($child, $key))
        ->assertOk()->assertJsonPath('data.ready', true);
}

function tvm_executor_sweep_finish(Operation $parent, Operation $observation): void
{
    $path = '/api/v1/operation-executor/'.$observation->id;
    test()->postJson($path.'/step', ['worker' => 'sweep-child', 'key' => 'observed', 'name' => 'sweep-observe', 'status' => 'succeeded', 'result' => ['ready' => true]])->assertOk();
    test()->postJson($path.'/complete', ['worker' => 'sweep-child', 'status' => 'succeeded', 'result' => ['ready' => true]])->assertOk();
    test()->postJson('/api/v1/operation-executor/'.$parent->id.'/complete', ['worker' => 'sweep-parent', 'status' => 'succeeded', 'result' => ['ready' => true]])->assertOk();
}

function tvm_executor_observe(Node $host): void
{
    $parent = tvm_executor_sweep($host);
    $child = tvm_executor_sweep_child($parent, 'observe');
    tvm_executor_sweep_run($child);
    tvm_executor_sweep_finish($parent, $child);
}

function tvm_executor_failed_image(Node $host): TaskVmImageBuild
{
    $recipe = app(RecipeLoader::class)->load();
    DB::table('task_vm_image_recipe')->where('id', 1)->update(['identity' => $recipe->identity, 'recipe_json' => $recipe->json, 'generation' => 1]);
    $build = TaskVmImageBuild::query()->create(['host_node_id' => $host->id, 'state' => 'failed', 'recipe_identity' => $recipe->identity,
        'recipe_json' => $recipe->json, 'recipe_generation' => 1, 'current_step' => TaskVmImageBuildStep::Recipe, 'current_program' => 'base:packages']);
    test()->travel(25)->hours();

    return $build;
}

describe('Swamp sweep executor safety', function (): void {
    beforeEach(function (): void {
        $gateway = tvm_sweep_node('gateway', '10.44.0.1');
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
        $services = tvm_sweep_node('services', '10.44.0.3');
        $services->accessibleNodes()->attach($gateway);
        config(['dependency_detection.services_node_id' => $services->id]);
        $this->withServerVariables(['REMOTE_ADDR' => $services->wireguard_ip]);
        $signer = Mockery::mock(LeafCertificateSigner::class);
        $signer->shouldReceive('rootCertificate')->andReturn('public root certificate');
        app()->instance(LeafCertificateSigner::class, $signer);
    });

    it('deletes only managed orphan names in each host project and leaves live and foreign resources intact', function (): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        $provisioning = tvm_sweep_vm($this->shark, TaskVmState::Provisioning, '10.44.0.130');
        $destroyed = tvm_sweep_vm($this->beast, TaskVmState::Destroyed, '10.44.0.131');
        $this->incus->instances = [
            '10.44.0.7' => [$ready->name, $destroyed->name, 'tvm-9999', 'tvm-image-20261010030000-sync-smoke', 'dev-box', $provisioning->name],
            '10.44.0.8' => [$provisioning->name, 'tvm-8888'],
        ];
        $this->incus->foreign = ['10.44.0.7' => ['default/tvm-9999', 'orbit-ci/tvm-8888']];
        foreach ([$this->beast, $this->shark] as $node) {
            $parent = tvm_executor_sweep($node);
            $plan = app(TaskVmFamilyChildren::class)->childContext($parent);
            foreach ($plan['children'] as $intent) {
                if ($intent['kind'] === 'task_vm.sweep_delete') {
                    tvm_executor_sweep_run(tvm_executor_sweep_child($parent, $intent['key']));
                }
            }
            $this->postJson('/api/v1/operation-executor/'.$parent->id.'/children', ['worker' => 'sweep-parent', 'key' => 'delete-instance-dev-box'])
                ->assertUnprocessable()->assertJsonPath('error.code', 'operation.child_forbidden');
        }
        expect($this->incus->instances)->toBe([
            '10.44.0.7' => [$ready->name, 'tvm-image-20261010030000-sync-smoke', 'dev-box'],
            '10.44.0.8' => [$provisioning->name],
        ]);
        expect($this->incus->foreign['10.44.0.7'])->toBe(['default/tvm-9999', 'orbit-ci/tvm-8888']);
        expect(array_all($this->incus->calls, static fn (array $call): bool => $call[1]['vm']['project'] === 'orbit-tasks'))->toBeTrue();
        expect(array_all($this->incus->reads, static fn (array $call): bool => $call['input']['project'] === 'orbit-tasks'))->toBeTrue();
        expect($ready->fresh()->state)->toBe(TaskVmState::Ready);
    });

    it('rechecks live VM ownership when a recorded orphan is claimed before deletion runs', function (): void {
        $this->incus->instances = ['10.44.0.7' => ['tvm-9000']];
        $parent = tvm_executor_sweep($this->beast);
        $deletion = tvm_executor_sweep_child($parent, 'delete-instance-tvm-9000');
        $claimed = tvm_sweep_vm($this->beast, TaskVmState::Provisioning, '10.44.0.129');
        $claimed->update(['name' => 'tvm-9000']);
        tvm_executor_sweep_run($deletion);
        expect($this->incus->instances['10.44.0.7'])->toBe(['tvm-9000']);
        expect($this->incus->calls)->toBe([]);
        expect($claimed->fresh()->state)->toBe(TaskVmState::Provisioning);
    });

    it('deletes only orphan clone volumes from the recorded host project and pool', function (): void {
        $live = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        $retained = [
            'orbit-tasks/orbit-tasks/'.$live->name.'-topology-seed',
            'orbit-tasks/orbit-tasks/orbit-topology-seed-aaaaaaaaaaaa',
            'orbit-tasks/orbit-tasks/scratch',
            'orbit-tasks/other/tvm-9999-topology-seed',
            'default/orbit-tasks/tvm-9999-topology-seed',
        ];
        $this->incus->volumes = ['10.44.0.7' => [...$retained, 'orbit-tasks/orbit-tasks/tvm-9999-topology-seed']];
        $parent = tvm_executor_sweep($this->beast);
        tvm_executor_sweep_run(tvm_executor_sweep_child($parent, 'delete-volume-tvm-9999-topology-seed'));
        expect($this->incus->volumes['10.44.0.7'])->toBe($retained);
        expect($this->incus->calls)->toHaveCount(1);
        expect($this->incus->calls[0][1]['location'])->toBe(['project' => 'orbit-tasks', 'pool' => 'orbit-tasks']);
    });

    it('requires two distinct sweep identities to fail missing ready and parked VMs', function (): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        $parked = tvm_sweep_vm($this->beast, TaskVmState::Parked, '10.44.0.130');
        $provisioning = tvm_sweep_vm($this->beast, TaskVmState::Provisioning, '10.44.0.131');
        $destroying = tvm_sweep_vm($this->beast, TaskVmState::Destroying, '10.44.0.132');
        $parent = tvm_executor_sweep($this->beast);
        $observation = tvm_executor_sweep_child($parent, 'observe');
        tvm_executor_sweep_run($observation);
        tvm_executor_sweep_run($observation);
        tvm_executor_sweep_run($observation, 'repeat-same-sweep');
        expect($ready->fresh()->state)->toBe(TaskVmState::Ready);
        expect($parked->fresh()->state)->toBe(TaskVmState::Parked);
        tvm_executor_sweep_finish($parent, $observation);
        tvm_executor_observe($this->beast);
        foreach ([$ready, $parked] as $vm) {
            expect($vm->fresh()->state)->toBe(TaskVmState::Failed);
            expect($vm->fresh()->error_code)->toBe('task_vm.vm_missing');
            expect($vm->group->fresh()->assistance_requested)->toBeFalse();
        }
        expect($provisioning->fresh()->state)->toBe(TaskVmState::Provisioning);
        expect($destroying->fresh()->state)->toBe(TaskVmState::Destroying);
    });

    it('clears missing evidence when the next sweep finds the VM', function (): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        tvm_executor_observe($this->beast);
        $this->incus->instances = ['10.44.0.7' => [$ready->name]];
        tvm_executor_observe($this->beast);
        $this->incus->instances = [];
        tvm_executor_observe($this->beast);
        expect($ready->fresh()->state)->toBe(TaskVmState::Ready);
        tvm_executor_observe($this->beast);
        expect($ready->fresh()->state)->toBe(TaskVmState::Failed);
    });

    it('fails closed when the host project becomes unreadable before the executor run', function (): void {
        $ready = tvm_sweep_vm($this->beast, TaskVmState::Ready, '10.44.0.129');
        $parent = tvm_executor_sweep($this->beast);
        $observation = tvm_executor_sweep_child($parent, 'observe');
        $this->incus->projectMissing = true;
        $this->postJson('/api/v1/operation-executor/'.$observation->id.'/run', tvm_executor_sweep_body($observation))
            ->assertStatus(502)->assertJsonPath('error.code', 'task_vm.host_command_failed');
        expect($ready->fresh()->state)->toBe(TaskVmState::Ready);
        expect($this->incus->calls)->toBe([]);
    });

    it('keeps running builders and recent failed builders, then expires only the failed retention', function (): void {
        $running = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id]);
        $failed = TaskVmImageBuild::query()->create(['host_node_id' => $this->beast->id, 'state' => 'failed']);
        $this->incus->instances = ['10.44.0.7' => [$running->builder_vm_name, $running->builder_vm_name.'-smoke', $failed->builder_vm_name]];
        tvm_executor_observe($this->beast);
        expect($this->incus->calls)->toBe([]);
        $this->travel(25)->hours();
        $parent = tvm_executor_sweep($this->beast);
        tvm_executor_sweep_run(tvm_executor_sweep_child($parent, 'delete-instance-'.$failed->builder_vm_name));
        expect($this->incus->instances['10.44.0.7'])->toBe([$running->builder_vm_name, $running->builder_vm_name.'-smoke']);
    });

    it('keeps a clone claimed after volume inventory is captured but before live rows are read', function (): void {
        $name = 'tvm-9000';
        $volume = $name.'-topology-seed';
        $this->incus->volumes = ['10.44.0.7' => ['orbit-tasks/orbit-tasks/'.$volume]];
        $parent = tvm_executor_sweep($this->beast);
        $deletion = tvm_executor_sweep_child($parent, 'delete-volume-'.$volume);
        $claimed = null;
        $this->incus->afterVolumes = function () use ($name, &$claimed): void {
            $this->incus->afterVolumes = null;
            $claimed = tvm_sweep_vm($this->beast, TaskVmState::Provisioning, '10.44.0.129');
            $claimed->update(['name' => $name]);
        };
        tvm_executor_sweep_run($deletion);
        expect($claimed)->toBeInstanceOf(TaskVm::class);
        expect($this->incus->volumes['10.44.0.7'])->toBe(['orbit-tasks/orbit-tasks/'.$volume]);
        expect($this->incus->calls)->toBe([]);
    });

    it('fences resume when sweep cleanup wins while the builder still exists', function (string $suffix): void {
        $build = tvm_executor_failed_image($this->beast);
        $name = $build->builder_vm_name.$suffix;
        $this->incus->instances['10.44.0.7'] = array_values(array_unique([$name, $build->builder_vm_name]));
        $parent = tvm_executor_sweep($this->beast);
        $deletion = tvm_executor_sweep_child($parent, 'delete-instance-'.$name);
        $overlapped = false;
        $this->incus->beforeExecute = function (array $input) use ($build, $name, &$overlapped): void {
            $this->incus->beforeExecute = null;
            $overlapped = true;
            expect($input['vm']['name'])->toBe($name);
            expect($this->incus->instances['10.44.0.7'])->toContain($build->builder_vm_name, $name);
            expect(fn () => app(TaskVmImageBuildChain::class)->resume($this->beast, $build->id))
                ->toThrow(TaskVmException::class, 'unclaimed failed build');
        };
        tvm_executor_sweep_run($deletion);
        expect($overlapped)->toBeTrue();
        expect($build->fresh()->cleanup_started_at)->not->toBeNull();
        expect($this->incus->instances['10.44.0.7'])->not->toContain($name);
        expect(Operation::query()->where('kind', 'task_vm.image_build')->exists())->toBeFalse();
    })->with(['builder' => '', 'smoke' => '-smoke']);

    it('skips an admitted deletion when resume wins the build cleanup fence', function (string $suffix): void {
        config(['swamp.run_token' => 'fixture.fake']);
        app()->instance(WebSocketClient::class, new FakeRoutePublicationSocket);
        $build = tvm_executor_failed_image($this->beast);
        $name = $build->builder_vm_name.$suffix;
        $this->incus->instances['10.44.0.7'] = array_values(array_unique([$name, $build->builder_vm_name]));
        $parent = tvm_executor_sweep($this->beast);
        $deletion = tvm_executor_sweep_child($parent, 'delete-instance-'.$name);
        app(TaskVmImageBuildChain::class)->resume($this->beast, $build->id);
        tvm_executor_sweep_run($deletion);
        expect($build->fresh()->state)->toBe(TaskVmImageBuildState::Running);
        expect($build->fresh()->cleanup_started_at)->toBeNull();
        expect($this->incus->instances['10.44.0.7'])->toContain($name, $build->builder_vm_name);
        expect($this->incus->calls)->toBe([]);
        expect(Operation::query()->where('kind', 'task_vm.image_build')->sole()->input['attempt'])->toBe(2);
    })->with(['builder' => '', 'smoke' => '-smoke']);

});
