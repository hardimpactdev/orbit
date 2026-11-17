<?php

declare(strict_types=1);

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Actions\Operations\CancelOperationAction;
use App\Actions\Operations\ExpireOperationAction;
use App\Data\Metrics\MetricsCredentialsData;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\GitHub\GitHubReview;
use App\Domain\Instances\ContainerDestroyExecutionCopies;
use App\Domain\Instances\ContainerInstanceDestroy;
use App\Domain\Instances\DevelopmentInstanceCreateProjection;
use App\Domain\Instances\DevelopmentInstanceDestroyProjection;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Domain\Proof\ProofChangedFile;
use App\Domain\Proof\ProofChangedFiles;
use App\Domain\Proof\ProofChildStarts;
use App\Domain\Proof\ProofCommandProjection;
use App\Domain\Proof\ProofCommitRange;
use App\Domain\Proof\ProofCommitSource;
use App\Domain\Proof\ProofCopies;
use App\Domain\Proof\ProofCopyHosts;
use App\Domain\Proof\ProofGroup;
use App\Domain\Proof\ProofRunIntent;
use App\Domain\Proof\ProofRunWatch;
use App\Domain\Proof\ProofWarmPool;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\AgentView\WebSocketEndpoint;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Jobs\Operations\ReconcileSwampOperations;
use App\Jobs\Proof\AdmitProofRun;
use App\Jobs\Proof\CancelProofGroup;
use App\Jobs\Tasks\DestroyTaskCopy;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Project;
use App\Models\ProofSeed;
use App\Models\ProofWarmCopy;
use App\Models\Route;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\WorkflowStartIntents;

uses(LazilyRefreshDatabase::class);

require_once __DIR__.'/../Instances/ContainerInstanceHelpers.php';

const COPY_PROOF_SHA = 'c0ffee0000000000000000000000000000000001';

/** GitHub as the copy proofs read it: a trusted branch head that changes a firewall workflow, whose cases file has two batches. */
function copyProofSource(): object
{
    return new class implements ProofCommitSource
    {
        /** @var list<string> */
        public array $branches = ['feature'];

        public int $trustReads = 0;

        public function commitExists(string $sha): bool
        {
            $this->trustReads++;

            return true;
        }

        public function branchesWithHead(string $sha): array
        {
            return $this->branches;
        }

        public function pullRequestsWithHead(string $sha): array
        {
            return [];
        }

        public function canWrite(string $login): bool
        {
            return false;
        }

        /** @return list<GitHubReview> */
        public function reviews(int $pullRequest): array
        {
            return [];
        }

        public function changes(string $sha): ProofChangedFiles
        {
            return new ProofChangedFiles(str_repeat('a', 40), [new ProofChangedFile('swamp/workflows/workflow-firewall-direct.yaml', 'modified')], true);
        }

        public function file(string $sha, string $path): ?string
        {
            return match ($path) {
                'swamp/proof/selection.txt' => "docs/**  skip\nswamp/workflows/workflow-firewall-*.yaml  firewall:allow\nswamp/proof/firewall/*.cases.yaml  firewall:allow\n",
                'swamp/proof/batches.yaml' => "batches:\n  fresh-fleet: {summary: Fresh}\n  no-grant: {summary: No grant}\n",
                default => "command: firewall:allow\ncases:\n  - {id: allow, batch: fresh-fleet}\n  - {id: refused, batch: no-grant}\n",
            };
        }

        public function caseSelection(string $base, string $head): ?array
        {
            return null;
        }

        public function proofPaths(string $sha): array
        {
            return ['swamp/proof/firewall/allow.cases.yaml'];
        }

        public ?array $history = null;

        public function history(string $base, string $head): ?array
        {
            return $this->history;
        }

        public function range(string $from, string $to): ?ProofCommitRange
        {
            return null;
        }
    };
}

beforeEach(function (): void {
    Queue::fake();
    $swamp = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'control', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209', 'user' => 'orbit',
    ]));
    $swamp->roles()->create(['role' => RoleName::Swamp, 'status' => 'active']);
    app(ExtensionStore::class)->set('conn', true);
    app(ExtensionStore::class)->set('tasks', true);
    app(TaskExtensionState::class)->enable();
    app()->instance(TaskVmModelRoute::class, new class implements TaskVmModelRoute
    {
        public function assertReady(): void {}

        public function origin(): string
        {
            return 'http://10.44.0.3:8318';
        }

        public function key(): string
        {
            return 'placeholder-model-key';
        }
    });
    // The bounded reads of a copy start (the default's container, its Node's ID map) and of a cancel (the copy's workspace).
    $this->ssh = new class implements SshExecutor
    {
        public int $calls = 0;

        /** @var array<string, mixed> */
        public array $workspace = ['state' => 'dirty', 'baseline' => 'none', 'head' => 'c0ffee', 'modified' => ['count' => 0, 'names' => []],
            'untracked' => ['count' => 3, 'names' => ['proof-evidence']], 'stashes' => ['count' => 0, 'names' => []],
            'commits' => ['count' => 1, 'branch_count' => 0, 'branches' => []]];

        public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
        {
            $this->calls++;
            if (str_contains((string) $command->input, 'ID map facts')) {
                return new CommandResult(0, json_encode(['uid' => [1000000, 1000000000], 'gid' => [1000000, 1000000000], 'containers' => []], JSON_THROW_ON_ERROR), '', 5, false);
            }

            return new CommandResult(0, json_encode($this->workspace, JSON_THROW_ON_ERROR), '', 5, false);
        }
    };
    app()->instance(SshExecutor::class, $this->ssh);
    $keys = Mockery::mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/fixture/key');
    app()->instance(SshKeyProvider::class, $keys);
    $hosts = Mockery::mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/fixture/known_hosts');
    app()->instance(KnownHostsStore::class, $hosts);

    $this->source = copyProofSource();
    app()->instance(ProofCommitSource::class, $this->source);
    $node = containerDefaultNode();
    $node->update(['settings' => [...$node->settings, 'nested_fleet' => true]]);
    // Prometheus as proof:run reads it: each CI Node's runner state and available memory (docs/cli/proof/run.mdx#the-run).
    $metrics = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'metrics', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '10.44.0.3', 'user' => 'orbit',
    ]));
    $metrics->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);
    $this->mock(MetricsCredentialManager::class, fn ($mock) => $mock->shouldReceive('storedCredentials')
        ->andReturn(new MetricsCredentialsData('https://metrics.orbit', 'admin', 'secret')));
    $this->ciNodes = [];
    $this->prometheusUp = true;
    Http::fake([
        'api.github.com/repos/nckrtl/trial' => Http::response(['full_name' => 'nckrtl/trial', 'private' => false]),
        '*/api/datasources' => Http::response([['type' => 'prometheus', 'uid' => 'prom']]),
        '*/api/datasources/proxy/uid/prom/api/v1/query*' => fn (Request $request) => ! $this->prometheusUp ? Http::response([], 503)
            : Http::response(['status' => 'success', 'data' => ['resultType' => 'vector', 'result' => copyProofSeries($this->ciNodes, str_contains((string) $request['query'], 'MemAvailable'))]]),
    ]);
    $this->project = Project::query()->create(['name' => 'trial', 'slug' => 'trial', 'type' => 'node-package', 'repository_url' => 'https://github.com/nckrtl/trial.git',
        'default_branch' => 'main', 'root' => '.', 'is_laravel' => false, 'source_facts_captured_at' => now(), 'nested_fleet' => true]);
    $default = Instance::query()->findOrFail(containerCreate($this->project, $node->refresh())->target_id);
    Route::query()->where('project_id', $this->project->id)->update(['status' => 'active', 'sites_published' => true]);
    $default->update(['status' => InstanceState::Active, 'first_setup_pending' => false]);
    InstanceRuntime::query()->where('instance_id', $default->id)->firstOrFail()
        ->update(['seed_commit' => str_repeat('c', 40), 'power_state' => InstanceRuntimePowerState::Running, 'inner_volume' => null]);
    Operation::query()->where('kind', 'instance.create')->update(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
    app(ProjectDevelopmentDeployStepStore::class)->create($this->project, new DevelopmentDeployStep('install-apps-gateway',
        'composer install --no-interaction --working-dir=apps/gateway', 300, true, ['apps/gateway/composer.lock']), null, null);
    config(['proof.project' => $this->project->id, 'proof.execution' => true, 'proof.copy_memory' => 8 * 1024 ** 3, 'orbit.tasks.copies_max' => 8]);
    // The default's own Node offers three CI places, idle, with memory for many copies.
    copyProofCiNode($node->refresh(), 3);
});

/**
 * Makes the Node a CI Node with its places, and records what Prometheus reads of it: its runners, `$busy` of them busy, its available
 * memory and how old the runner state is.
 */
function copyProofCiNode(Node $node, int $places, int $busy = 0, int $memory = 64 * 1024 ** 3, int $age = 5): Node
{
    // Its passed ci-runner converge installed this release's hold script (docs/reference/node-provisioning.md#proof-places-on-a-ci-node).
    $node->forceFill(['settings' => [...($node->settings ?? []), 'ci' => ['places' => $places, 'proof_only' => false]], 'ci_hold_digest' => ProofCopyHosts::holdDigest()])->save();
    if (! $node->roles()->where('role', RoleName::CiRunner)->exists()) {
        $node->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
    }
    test()->ciNodes = [...test()->ciNodes, $node->name => ['places' => $places, 'busy' => $busy, 'memory' => $memory, 'age' => $age]];
    Cache::flush();

    return $node->refresh();
}

/**
 * The node exporter series of the CI Nodes: the runner state, or the available memory.
 *
 * @param  array<string, array{places: int, busy: int, memory: int, age: int}>  $nodes
 * @return list<array<string, mixed>>
 */
function copyProofSeries(array $nodes, bool $memory): array
{
    $series = [];
    $line = static fn (string $name, string $node, int $value, array $labels = []): array => ['metric' => ['__name__' => $name, 'job' => 'orbit-node-exporter', 'node' => $node, ...$labels], 'value' => [now()->getTimestamp(), (string) $value]];
    foreach ($nodes as $name => $node) {
        if ($memory) {
            $series[] = $line('node_memory_MemAvailable_bytes', $name, $node['memory']);

            continue;
        }
        $series[] = $line('orbit_ci_runners_observed_timestamp_seconds', $name, now()->getTimestamp() - $node['age']);
        $series[] = $line('orbit_ci_runners_read_ok', $name, 1);
        foreach (range(1, $node['places']) as $index) {
            $series[] = $line('orbit_ci_runner_info', $name, 1, ['runner' => $name.'-'.$index, 'state' => 'running', 'service' => 'active', 'version' => '2.330.0']);
            $series[] = $line('orbit_ci_runner_busy', $name, $index <= $node['busy'] ? 1 : 0, ['runner' => $name.'-'.$index]);
        }
    }

    return $series;
}

/** The request, then the queue job: the proof as the admission left it. */
function copyProofAdmitted(): Operation
{
    $proof = app(ProofRunIntent::class)->start(COPY_PROOF_SHA, false, 'tester', null);
    app(ProofRunIntent::class)->admit($proof->id);

    return $proof->refresh();
}

/** @return Collection<int, Operation> */
function copyProofRuns(): Collection
{
    return Operation::query()->where('kind', 'proof.command')->where('input->copy', true)->orderBy('id')->get();
}

/** The copy of a run as its create and completion leave it: active. */
function copyProofActive(Operation $run): Instance
{
    $copy = Instance::query()->findOrFail($run->input['instance_id']);
    $copy->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => $copy->name, 'starting_commit' => str_repeat('c', 40)]);
    InstanceRuntime::query()->where('instance_id', $copy->id)->update(['power_state' => InstanceRuntimePowerState::Running]);
    Operation::query()->where('target_type', 'instance')->where('target_id', $copy->id)->update(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
    Task::topLevel()->whereKey($run->input['group_id'])->update(['status' => TaskGroupStatus::Running]);

    return $copy->refresh();
}

describe('proof:run in copies: admission', function (): void {
    it('checks trust before any copy exists: an untrusted commit makes no copy and reads no Node', function (): void {
        $this->source->branches = [];
        $proof = copyProofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Failed)->and($proof->error_code)->toBe('proof.commit_untrusted')
            ->and(Instance::query()->whereNotNull('task_workspace_owner_id')->count())->toBe(0)
            ->and(Operation::query()->where('kind', 'instance.create')->where('status', '!=', 'succeeded')->count())->toBe(0)
            ->and(Task::topLevel()->count())->toBe(0)
            ->and($this->ssh->calls)->toBe(0);
    });

    it('starts one copy per shard, one shard per free place and at most one per batch, and a held run for each that names its copy', function (): void {
        $proof = copyProofAdmitted();
        $runs = copyProofRuns();
        $copies = Instance::query()->whereNotNull('task_workspace_owner_id')->get();

        expect($proof->status)->toBe(OperationStatus::Running)
            ->and($runs)->toHaveCount(2)->and($copies)->toHaveCount(2)
            ->and(Operation::query()->where('kind', 'instance.create')->whereIn('target_id', $copies->pluck('id'))->count())->toBe(2)
            ->and($runs->every(static fn (Operation $run): bool => $run->input['copy'] === true && $copies->contains('id', $run->input['instance_id'])))->toBeTrue()
            // One unmeasured batch a shard: (600 + 180 + 1) x 1.5 s, rounded up to five minutes.
            ->and($runs->pluck('input.run_budget_seconds')->all())->toBe([1200, 1200])
            ->and(collect($proof->input['runs'])->pluck('instance_id')->sort()->values()->all())->toBe($copies->pluck('id')->sort()->values()->all())
            ->and(app(DirectOperation::class)->workflowFor($runs->first()))->toBe('operation-proof-run-copy')
            ->and(Task::topLevel()->whereKey($runs->pluck('target_id'))->get()->every(static fn (Task $group): bool => $group->status === TaskGroupStatus::Reserved))->toBeTrue();
    });

    it('takes the free CI places of the Node: its places minus busy runners and proof copies, within its available memory', function (int $places, int $busy, int $memoryCopies, int $runs): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), $places, $busy, $memoryCopies * 8 * 1024 ** 3 + 1024);
        $proof = copyProofAdmitted();

        expect(copyProofRuns())->toHaveCount($runs)->and(Instance::query()->whereNotNull('task_workspace_owner_id')->count())->toBe($runs)
            ->and($proof->status)->toBe($runs === 0 ? OperationStatus::Waiting : OperationStatus::Running);
        if ($runs === 0) {
            expect($proof->current_step)->toBe('Wait for a free CI place');
        }
    })->with([
        'three idle places: one copy per batch' => [3, 0, 8, 2],
        'one busy runner of two places: one copy' => [2, 1, 8, 1],
        'every place busy: it waits' => [2, 2, 8, 0],
        'memory for one copy peak: one copy' => [3, 0, 1, 1],
        'memory short of one copy peak: it waits' => [3, 0, 0, 0],
    ]);

    it('counts the proof copies already on the Node, and no task copy, against its places', function (): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 2);
        copyProofOtherCopies(3);
        $default = Instance::query()->where('name', 'default')->firstOrFail();
        $proofCopy = Instance::query()->where('name', 'other-1')->firstOrFail();
        Operation::query()->create(['kind' => ProofGroup::OwnerKind, 'target_type' => 'task_group', 'target_id' => $proofCopy->task_workspace_owner_id,
            'node_id' => $default->node_id, 'status' => OperationStatus::Running, 'input' => [], 'triggered_by' => 'test']);

        copyProofAdmitted();

        expect(copyProofRuns()->where('input.proof_operation_id', '!=', null)->count())->toBe(1);
    });

    it('waits while Prometheus does not answer or the runner state is stale', function (Closure $change): void {
        $change->call($this);
        $proof = copyProofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Waiting)->and(copyProofRuns())->toHaveCount(0)
            ->and(Instance::query()->whereNotNull('task_workspace_owner_id')->count())->toBe(0);
    })->with([
        'Prometheus down' => [function (): void {
            $this->prometheusUp = false;
        }],
        'a stale runner state' => [fn () => copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 3, age: 600)],
    ]);

    it('places copies on several CI Nodes, each from its own Node\'s seed, and none on a Node whose seed is behind', function (): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 1);
        // The Node whose seed is behind comes first in the Nodes' order, so only the seed rule keeps a copy off it.
        $behind = copyProofCiOnlyNode('ci-b', '10.44.0.22', current: false);
        $ci = copyProofCiOnlyNode('ci-a', '10.44.0.21', current: true);
        $proof = copyProofAdmitted();
        $copies = Instance::query()->whereNotNull('task_workspace_owner_id')->with('runtime')->get();

        expect($behind->id)->toBeLessThan($ci->id)->and($copies->pluck('node_id'))->not->toContain($behind->id)
            ->and($proof->status)->toBe(OperationStatus::Running)->and(copyProofRuns())->toHaveCount(2)
            ->and($copies->pluck('node_id')->sort()->values()->all())->toBe(collect([Node::query()->where('name', 'beast')->value('id'), $ci->id])->sort()->values()->all())
            ->and($copies->every(static fn (Instance $copy): bool => $copy->runtime?->clone_source_instance_id === Instance::query()->where('name', 'default')->value('id')))->toBeTrue();
        $onCi = $copies->firstWhere('node_id', $ci->id);
        $clone = app(InstanceContainer::class)->intent($onCi, $onCi->runtime)['clone'] ?? null;
        // The copy on the CI-only Node keeps its inner fleet: its create intent starts it, so the copy's fleet can be reset.
        expect(app(NestedFleetIntent::class)->forClone($onCi, $onCi->runtime))->not->toBeNull();
        // The copy needs no Git mirror of the Project on its Node: its run fetches the commit by its SHA.
        expect($clone['source'] ?? null)->toBe('orbit-proof-seed-ci-a')->and($clone['seed_commit'] ?? null)->toBe(str_repeat('c', 40))
            ->and($clone)->not->toHaveKey('source_mirror');
        // Each copy's create holds its CI place with its Node's ci_places, and its destroy releases it
        // (docs/reference/node-provisioning.md#proof-places-on-a-ci-node). A task copy holds none.
        foreach ($copies as $copy) {
            $create = Operation::query()->where('kind', 'instance.create')->where('target_id', $copy->id)->sole();
            expect($create->input['direct_provisioning']['container']['clone']['ci_hold'] ?? null)
                ->toBe(['places' => ProofCopyHosts::places(Node::query()->findOrFail($copy->node_id)), 'node' => Node::query()->findOrFail($copy->node_id)->name])
                ->and(app(ContainerInstanceDestroy::class)->capture($copy)['ci_hold'] ?? null)->toBeTrue();
        }
        copyProofOtherCopies(1);
        $task = Instance::query()->where('name', 'other-1')->firstOrFail();
        expect(app(ContainerInstanceDestroy::class)->capture($task))->not->toHaveKey('ci_hold');
    });
});

describe('proof:run in copies: the hold script', function (): void {
    it('places no copy on a Node without this release\'s hold script until its ci-runner converge installs it', function (?string $recorded): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 1);
        $ci = copyProofCiOnlyNode('ci-a', '10.44.0.21', current: true);
        Node::query()->whereKey($ci->id)->update(['ci_hold_digest' => $recorded === null ? null : hash('sha256', $recorded)]);
        copyProofAdmitted();

        expect(Instance::query()->whereNotNull('task_workspace_owner_id')->pluck('node_id')->all())
            ->toBe([Node::query()->where('name', 'beast')->value('id')]);
    })->with([
        'no record: the Node was not converged since the script came' => [null],
        'a changed script: the Node holds an older one' => ['an older hold script'],
    ]);

    it('places no copy on a CI-only Node whose ci-runner converge did not prepare nested hosting', function (): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 1);
        $ci = copyProofCiOnlyNode('ci-a', '10.44.0.21', current: true);
        $ci->forceFill(['ci_nested_hosting' => false])->save();
        $proof = copyProofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Running)
            ->and(Instance::query()->whereNotNull('task_workspace_owner_id')->pluck('node_id')->all())->toBe([Node::query()->where('name', 'beast')->value('id')]);
    });
});

/** Task copies of other groups on the default's Node: each counts as a live task copy, and takes no CI place. */
function copyProofOtherCopies(int $count): void
{
    $default = Instance::query()->where('name', 'default')->firstOrFail();
    foreach (range(1, $count) as $index) {
        $group = Task::query()->withoutGlobalScopes()->create(['project_id' => $default->project_id, 'title' => 'Other '.$index, 'brief' => 'Other', 'status' => TaskGroupStatus::Running]);
        $copy = $default->replicate();
        $copy->fill(['name' => 'other-'.$index, 'checkout_path' => '/srv/other-'.$index, 'task_workspace_owner_id' => $group->id])->save();
        $runtime = $default->runtime->replicate();
        $runtime->fill(['instance_id' => $copy->id, 'container_name' => 'other-'.$index, 'bridge_address' => '10.253.0.'.(200 + $index), 't3_port' => null])->save();
    }
}

/**
 * A CI-only Node that offers proof places, idle, whose seed of the proof default is current or one seed behind. It has no nested-fleet
 * switch: its ci-runner converge prepared nested hosting and recorded it (docs/reference/projects.md#nested-fleet-setting).
 */
function copyProofCiOnlyNode(string $name, string $address, bool $current): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $address, 'public_ssh_host' => $address, 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
    $node->forceFill(['ci_nested_hosting' => true])->save();
    app(AllocateInstanceBridgeAction::class)->execute($node);
    $node = copyProofCiNode($node->refresh(), 2);
    $default = InstanceRuntime::query()->whereHas('instance', static fn ($instance) => $instance->where('name', 'default'))->firstOrFail();
    $default->update(['seed_taken_at' => $default->seed_taken_at ?? now()->subHour()]);
    ProofSeed::query()->create(['node_id' => $node->id, 'holder' => 'orbit-proof-seed-'.$name, 'operation_id' => 1,
        'commit' => $current ? $default->seed_commit : str_repeat('b', 40), 'taken_at' => $default->seed_taken_at]);

    return $node;
}

describe('proof:run in copies: the wait for a free CI place', function (): void {
    it('reads waiting with the wait text and no start time while held, and the status filter finds it', function (): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 2, busy: 2);
        $proof = copyProofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Waiting)->and($proof->current_step)->toBe(ProofCopies::WaitStep)
            ->and($proof->started_at)->toBeNull()->and($proof->finished_at)->toBeNull()
            ->and(Operation::query()->where('kind', 'proof.run')->where('status', OperationStatus::Waiting)->pluck('id')->all())->toBe([$proof->id])
            ->and(Operation::query()->where('kind', 'proof.run')->where('status', OperationStatus::Running)->exists())->toBeFalse();
        Queue::assertPushed(AdmitProofRun::class, static fn (AdmitProofRun $job): bool => $job->operationId === $proof->id);
    });

    it('keeps the selection and the batch plan of its first pass on each retry, and reads GitHub no more', function (): void {
        // docs/cli/proof/run.mmd: a retry after a wait for a place does not check the commit, select the cases or plan the batches again.
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 2, busy: 2);
        $proof = copyProofAdmitted();
        $first = $proof->input;
        $reads = $this->source->trustReads;
        // A trust check now would refuse the commit: no branch holds it.
        $this->source->branches = [];

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Waiting)->and($this->source->trustReads)->toBe($reads)
            ->and($first['batches'])->not->toBe([])->and($proof->input['cases'])->toBe($first['cases'])
            ->and($proof->input['batches'])->toBe($first['batches']);
    });

    it('cancels a waiting proof, and its retry job then leaves it cancelled', function (): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 2, busy: 2);
        $proof = copyProofAdmitted();
        expect($proof->status)->toBe(OperationStatus::Waiting);

        app(CancelOperationAction::class)->execute($proof);
        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Cancelled)->and($proof->finished_at)->not->toBeNull()->and($proof->current_step)->toBeNull()
            ->and(copyProofRuns())->toHaveCount(0);
    });

    it('expires the admission allowance for operators as well as CI before it records any run or copy', function (bool $ci): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 2, busy: 2);
        $caller = Node::query()->where('name', 'control')->firstOrFail();
        $caller->roles()->create(['role' => RoleName::CiRunner, 'status' => 'active']);
        $proof = app(ProofRunIntent::class)->start(COPY_PROOF_SHA, false, 'test', null, authenticatedCaller: $ci ? $caller : null);
        app(ProofRunIntent::class)->admit($proof->id);
        expect($proof->refresh()->status)->toBe(OperationStatus::Waiting)->and($proof->input['ci_pool'])->toBe($ci);

        $proof->forceFill(['created_at' => now()->subSeconds(ProofRunIntent::WaitSeconds + 1)])->save();
        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->error_code)->toBe('proof.capacity_timeout')
            ->and($proof->status)->toBe(OperationStatus::Failed)->and($proof->started_at)->toBeNull()
            ->and(copyProofRuns())->toHaveCount(0)->and(Instance::query()->whereNotNull('task_workspace_owner_id')->count())->toBe(0);
    })->with(['operator' => false, 'CI' => true]);
});

describe('proof:run in copies: the shard budget', function (): void {
    it('waits for another CI place rather than plan a shard past the longest shard budget, and runs it once every place is free', function (): void {
        // Measured seconds: both batches on one copy take (600 + 2 x 180 + 6,000) x 1.5 s, more than one shard may take.
        $default = Instance::query()->where('name', 'default')->firstOrFail();
        Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'node', 'target_id' => $default->node_id, 'node_id' => $default->node_id,
            'status' => OperationStatus::Succeeded, 'input' => [], 'result' => ['cases' => [
                ['program' => 'swamp/proof/firewall/allow.cases.yaml#allow', 'seconds' => 3000], ['program' => 'swamp/proof/firewall/allow.cases.yaml#refused', 'seconds' => 3000]]]]);
        $node = copyProofCiNode(Node::query()->findOrFail($default->node_id), 3, busy: 2);
        $proof = copyProofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Waiting)->and($proof->current_step)->toBe('Wait for a free CI place')
            ->and($proof->state['capacity_wait_reason'])->toContain('Waiting for more free CI places')
            ->and(copyProofRuns())->toHaveCount(0);

        // With every place free, it runs: one batch on each of two places.
        copyProofCiNode($node, 3);
        app(ProofRunIntent::class)->admit($proof->id);
        expect($proof->refresh()->status)->toBe(OperationStatus::Running)->and(copyProofRuns())->toHaveCount(2);
    });
});

describe('proof:run in copies: the start waits for the copy', function (): void {
    it('holds a run while its copy is not active, and releases it on an active copy', function (): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        app(OperationStore::class)->change($run, ['state' => [...($run->state ?? []), 'proof_child_start' => ['not_before' => now()->subSecond()->toISOString(), 'released_at' => null]]]);

        expect(app(ProofChildStarts::class)->release($run->refresh()))->toBeInstanceOf(CarbonImmutable::class);
        copyProofActive($run);
        expect(app(ProofChildStarts::class)->release($run->refresh()))->toBeTrue();
    });

    it('fails a run whose copy failed, with its cases unrun, and cancels its group', function (): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        Task::topLevel()->whereKey($run->input['group_id'])->update(['status' => TaskGroupStatus::Failed]);
        app(OperationStore::class)->change($run, ['state' => [...($run->state ?? []), 'proof_child_start' => ['not_before' => now()->subSecond()->toISOString(), 'released_at' => null]]]);

        expect(app(ProofChildStarts::class)->release($run->refresh()))->toBeFalse();
        $run->refresh();
        expect($run->status)->toBe(OperationStatus::Failed)->and($run->error_code)->toBe(ProofCommandProjection::RunFailed)
            ->and(collect($run->result['cases'])->pluck('verdict')->unique()->all())->toBe(['unrun']);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    });

    // A held run admitted before the task VM proof path was removed carries no copy: nothing can start it, so it fails like a run
    // whose copy is gone, instead of being released to a VM workflow.
    it('fails a held run that is not in a copy, with its cases unrun, and cancels its group', function (): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        app(OperationStore::class)->change($run, ['input' => [...$run->input, 'copy' => false],
            'state' => [...($run->state ?? []), 'proof_child_start' => ['not_before' => now()->subSecond()->toISOString(), 'released_at' => null]]]);

        expect(app(ProofCopies::class)->state($run->refresh()))->toBe('failed')
            ->and(app(ProofChildStarts::class)->release($run))->toBeFalse();
        expect($run->refresh()->status)->toBe(OperationStatus::Failed)->and($run->error_code)->toBe(ProofCommandProjection::RunFailed);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    });
});

describe('proof:run in copies: when a copy will never be ready', function (): void {
    it('reads a copy that is gone or whose completion failed as failed, and a copy still being made as pending', function (Closure $change, ?string $state): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        $change(Instance::query()->findOrFail($run->input['instance_id']));

        expect(app(ProofCopies::class)->state($run->refresh()))->toBe($state);
    })->with([
        'being made' => [static fn (Instance $copy) => null, 'pending'],
        'gone' => [static fn (Instance $copy) => Task::topLevel()->whereKey($copy->task_workspace_owner_id)->update(['taskable_id' => null]), 'failed'],
        // A proof copy has no later create: a create that ended by its expired budget leaves a copy that is never ready.
        'create timed out' => [static fn (Instance $copy) => Operation::query()->where('kind', 'instance.create')->where('target_id', $copy->id)
            ->update(['status' => OperationStatus::Failed, 'error_code' => 'operation.timed_out', 'finished_at' => now(), 'lock_key' => null]), 'failed'],
        'completion failed' => [static function (Instance $copy): void {
            $copy->update(['status' => InstanceState::SourceResolved]);
            Operation::query()->create(['kind' => 'instance.complete', 'target_type' => 'instance', 'target_id' => $copy->id, 'node_id' => $copy->node_id,
                'input' => [], 'state' => [], 'status' => OperationStatus::Failed, 'error_code' => 'instance.complete_failed', 'finished_at' => now(), 'sequence' => 1, 'attempts' => 0]);
        }, 'failed'],
    ]);

    // Swamp reopens an expiry whose run met its real budget (ExpireOperationAction::reopen): only a settled expiry ends the create.
    it('reads a create whose budget expired as pending until the expiry is settled, then as failed', function (Closure $settle): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        $create = Operation::query()->where('kind', 'instance.create')->where('target_id', $run->input['instance_id'])->sole();
        $create->forceFill(['status' => 'running', 'started_at' => now()->subDay(), 'state' => [...($create->state ?? []), 'swamp_run_id' => 'run-1']])->save();
        $create = app(ExpireOperationAction::class)->execute($create->refresh());

        expect($create->status)->toBe(OperationStatus::Failed)->and($create->error_code)->toBe('operation.timed_out')
            ->and(ExpireOperationAction::expiredUnsettled($create))->toBeTrue()
            ->and(app(ProofCopies::class)->state($run->refresh()))->toBe('pending');

        $settle($create);

        expect(ExpireOperationAction::expiredUnsettled($create->refresh()))->toBeFalse()
            ->and(app(ProofCopies::class)->state($run->refresh()))->toBe('failed');
    })->with([
        'Swamp proved the run ended' => [static fn (Operation $create) => app(ExpireOperationAction::class)->settle($create, 'run-1', 'failed')],
        'no proof within the hold' => [static function (Operation $create): void {
            test()->travel(ExpireOperationAction::HoldMinutes + 1)->minutes();
            expect(app(ExpireOperationAction::class)->releaseStaleHolds())->toBe(1);
        }],
    ]);
});

describe('proof:run in copies: the start input', function (): void {
    it('brings in the commit from the public repository and proves each batch in the copy', function (): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        copyProofActive($run);
        app()->instance(WebSocketClient::class, WorkflowStartIntents::vault());
        $inputs = app(DirectOperation::class)->workflowInputs($run->refresh());

        expect($inputs['intent']['commit'])->toBe(COPY_PROOF_SHA)
            ->and($inputs['intent']['checkout']['repository'])->toBe('https://github.com/hardimpactdev/orbit.git')
            ->and($inputs['intent']['checkout']['commit'])->toBe(COPY_PROOF_SHA)
            ->and(array_column($inputs['intent']['checkout']['deployment']['steps'], 'name'))->toBe(['install-apps-gateway'])
            ->and(array_keys($inputs['execution_secrets']))->toBe(['step_0'])
            ->and($inputs['intent']['verifications'][0]['copy'])->toBe(['project' => 'orbit-instances-nested', 'name' => Instance::query()->findOrFail($run->input['instance_id'])->runtime->container_name])
            ->and($inputs['intent']['verifications'][0]['restore_first'])->toBeTrue()
            ->and(json_encode($inputs))->not->toContain('composer install');
        WorkflowStartIntents::match('proof_run_copy', $inputs);
    });

    it('resets the fleet before the first batch and after each batch that can change it, not after a read-only batch', function (): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        copyProofActive($run);
        $batch = static fn (string $name, bool $readOnly): array => ['command' => $run->input['command'], 'file' => $run->input['file'], 'batch' => $name,
            'cases' => ['a'], 'seconds' => 1.0, 'read_only' => $readOnly];
        app(OperationStore::class)->change($run->refresh(), ['input' => [...$run->input,
            'assignments' => [$batch('fresh', true), $batch('fresh', false), $batch('node-down', false)]]]);
        app()->instance(WebSocketClient::class, WorkflowStartIntents::vault());
        $inputs = app(DirectOperation::class)->workflowInputs($run->refresh());

        expect(array_column($inputs['intent']['verifications'], 'restore_first'))->toBe([true, false, true]);
    });
});

describe('proof:run in copies: every end removes the copy', function (): void {
    it('cancels the group with discard at every end of the run', function (string $status): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        $run->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']])->save();
        app(ProofCommandProjection::class)->apply($run->refresh(), ['status' => $status, 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'result' => ['report' => '']]);

        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
    })->with(['succeeded', 'failed', 'cancelled', 'interrupted']);

    it('cancels the group of a refused and of a timed-out run', function (): void {
        copyProofAdmitted();
        [$refused, $timedOut] = copyProofRuns()->all();
        app(ProofCommandProjection::class)->refused($refused);
        app(ProofCommandProjection::class)->timedOut($timedOut);

        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $refused->id);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $timedOut->id);
    });

    it('destroys a copy that holds the commit and the evidence: the cancel discards them', function (): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        $copy = copyProofActive($run);
        app(OperationStore::class)->change($run, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'lock_key' => null]);

        app(ProofGroup::class)->cancel($run->refresh());

        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists())->toBeTrue();
    });

    // A create that fails after `clone-ci-hold` holds the copy's CI place: every end of it destroys the copy with the release
    // (docs/reference/node-provisioning.md#proof-places-on-a-ci-node).
    it('destroys a copy whose create ended without success, with the release of its hold, and fails its run', function (string $status, ?string $code): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        $copy = Instance::query()->findOrFail($run->input['instance_id']);
        $create = Operation::query()->where('kind', 'instance.create')->where('target_id', $copy->id)->sole();
        $create->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']])->save();
        app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), ['status' => $status, 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'error_code' => $code, 'result' => ['ready' => false, 'instance_id' => $copy->id, 'error_code' => $code]]);
        if ($code === 'operation.timed_out') {
            app(OperationStore::class)->change($create->refresh(), ['status' => OperationStatus::Failed, 'error_code' => $code, 'lock_key' => null]);
        }
        foreach (Queue::pushed(DestroyTaskCopy::class) as $job) {
            app()->call([$job, 'handle']);
        }
        app(OperationStore::class)->change($run->refresh(), ['state' => [...($run->state ?? []), 'proof_child_start' => ['not_before' => now()->subSecond()->toISOString(), 'released_at' => null]]]);

        expect(app(ProofChildStarts::class)->release($run->refresh()))->toBeFalse()
            ->and($run->refresh()->status)->toBe(OperationStatus::Failed);
        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
        // A destroy that the create's end queued holds the copy, and the run's cancel waits for it; without one, the cancel destroys it.
        if (! Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->exists()) {
            app(ProofGroup::class)->cancel($run->refresh());
        }
        $destroys = Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->get();
        expect($destroys)->toHaveCount(1)->and($destroys->sole()->input['container_destroy']['ci_hold'] ?? null)->toBeTrue();
    })->with([
        'failed at the copy' => ['failed', 'instance.container_network_unsafe'],
        'cancelled' => ['cancelled', 'operation.cancelled'],
        'its budget expired' => ['failed', 'operation.timed_out'],
    ]);

    // A CI-only Node has no app role: the removal contract places a copy there as the Gateway does (Instance::placementEnvironment), so
    // the destroy of a copy whose create failed is admitted on every path, and once it succeeds the runner view counts no copy.
    it('removes a failed copy on a CI-only Node with the release of its hold, by its group\'s failure, its destroy and its cancel', function (string $path): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 3, busy: 3);
        $ci = copyProofCiOnlyNode('ci-a', '10.44.0.21', current: true);
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        $copy = Instance::query()->findOrFail($run->input['instance_id']);
        $create = Operation::query()->where('kind', 'instance.create')->where('target_id', $copy->id)->sole();
        $create->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-1']])->save();
        app(DevelopmentInstanceCreateProjection::class)->apply($create->refresh(), ['status' => 'failed', 'run_id' => 'run-1', 'started_at' => now()->toIso8601String(),
            'finished_at' => now()->toIso8601String(), 'error_code' => 'instance.create_clone_failed',
            'result' => ['ready' => false, 'instance_id' => $copy->id, 'failed_step' => 'clone-container', 'error_code' => 'instance.create_clone_failed']]);
        expect($copy->refresh()->node_id)->toBe($ci->id)->and($copy->status)->toBe(InstanceState::Reserved)
            ->and(Task::topLevel()->findOrFail($run->input['group_id'])->status)->toBe(TaskGroupStatus::Failed)
            ->and(ProofCopyHosts::copies()->whereKey($copy->id)->exists())->toBeTrue();

        // The operator's call reaches the API from the Gateway Node, as the CLI's does.
        Node::query()->where('name', 'control')->firstOrFail()->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $caller = $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.209']);
        match ($path) {
            'group failure' => Queue::assertPushed(DestroyTaskCopy::class, static fn (DestroyTaskCopy $job): bool => $job->instanceId === $copy->id && app()->call([$job, 'handle']) === null),
            'destroy' => $caller->deleteJson('/api/v1/instances/'.$copy->id, ['force' => true])->assertStatus(202),
            'cancel' => $caller->withHeader('Idempotency-Key', 'cancel-copy')->postJson('/api/v1/tasks/'.$run->input['group_id'].'/cancel', ['discard' => true])->assertSuccessful(),
        };

        $destroy = Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->sole();
        expect($destroy->input['container_destroy']['ci_hold'] ?? null)->toBeTrue();
        $destroy->forceFill(['status' => 'running', 'state' => ['swamp_run_id' => 'run-2'], 'input' => Arr::except($destroy->input, 'container_destroy')])->save();
        app(DevelopmentInstanceDestroyProjection::class)->apply($destroy->refresh(), ['status' => 'succeeded', 'run_id' => 'run-2',
            'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => []]);
        expect(Instance::query()->find($copy->id))->toBeNull()->and(ProofCopyHosts::copies()->whereKey($copy->id)->exists())->toBeFalse();
    })->with(['group failure', 'destroy', 'cancel']);

    it('keeps the removal contract strict: a Node whose ci-runner role is being removed places no removal', function (string $role, bool $admitted): void {
        copyProofCiNode(Node::query()->where('name', 'beast')->firstOrFail(), 3, busy: 3);
        $ci = copyProofCiOnlyNode('ci-a', '10.44.0.21', current: true);
        copyProofAdmitted();
        $copy = Instance::query()->findOrFail(copyProofRuns()->first()->input['instance_id']);
        $copy->update(['failed_step' => 'clone-container', 'error_code' => 'instance.create_clone_failed']);
        NodeRole::query()->where('node_id', $ci->id)->where('role', RoleName::CiRunner)->update(['status' => $role]);
        $insert = static fn () => DB::table('instance_removals')->insert(['kind' => 'destroy', 'id' => (string) Str::uuid(), 'requested_instance_id' => $copy->id,
            'requested_name' => $copy->name, 'force' => true, 'inventory_digest' => str_repeat('a', 64), 'total' => 1, 'status' => 'removing',
            'current_step' => 'source_preparation', 'created_at' => now(), 'updated_at' => now()]);

        $admitted ? expect($insert())->toBeTrue() : expect($insert)->toThrow(QueryException::class, 'Invalid Instance removal contract.');
    })->with(['an active role' => ['active', true], 'a role being removed' => ['removing', false]]);

    it('admits a new destroy when the copy\'s destroy failed, so a later cancel is never a replay of the failed one', function (): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        $copy = copyProofActive($run);
        app(OperationStore::class)->change($run, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'lock_key' => null]);
        app(ProofGroup::class)->cancel($run->refresh());
        $first = Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->sole();
        app(OperationStore::class)->change($first, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'lock_key' => null]);

        app(ProofGroup::class)->cancel($run->refresh());

        expect(Operation::query()->where('kind', 'instance.destroy')->where('target_id', $copy->id)->pluck('id')->all())->toHaveCount(2);
    });

    it('removes the deploy step copies of the copy\'s proof runs with the copy', function (): void {
        $socket = new class implements WebSocketClient
        {
            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function connect(WebSocketEndpoint $endpoint, float $timeoutSeconds): void {}

            public function send(array $message): void
            {
                $this->sent[] = $message;
            }

            public function receive(float $timeoutSeconds): array
            {
                $frame = end($this->sent);

                return [['id' => is_array($frame) ? ($frame['id'] ?? null) : null, 'type' => is_array($frame) ? ($frame['type'] ?? null) : null, 'payload' => ['data' => []]]];
            }

            public function close(): void {}

            public function isConnected(): bool
            {
                return true;
            }
        };
        config(['swamp.address' => 'swamp.test', 'swamp.ca_path' => '/tmp/ca.pem', 'swamp.run_token' => 'run-token', 'swamp.read_token' => 'read-token']);
        app()->instance(WebSocketClient::class, $socket);
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        $destroy = Operation::query()->create(['kind' => 'instance.destroy', 'target_type' => 'instance', 'target_id' => $run->input['instance_id'],
            'node_id' => $run->node_id, 'input' => [], 'state' => [], 'status' => OperationStatus::Succeeded, 'sequence' => 1, 'attempts' => 0]);

        app(ContainerDestroyExecutionCopies::class)->remove($destroy);

        expect(collect($socket->sent)->where('type', 'vault.delete')->pluck('payload.key')->all())
            ->toContain('development-deploy-operation-'.$run->id.'-step-0', 'development-deploy-operation-'.$run->id.'-step-31');
    });

    it('cancels the groups of a cancelled proof', function (): void {
        $proof = copyProofAdmitted();
        app(CancelOperationAction::class)->execute($proof);

        foreach (copyProofRuns() as $run) {
            Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $run->id);
        }
    });

    it('asks again for the cancel of an ended run whose copy is still there, as after a Gateway restart', function (): void {
        copyProofAdmitted();
        [$ended, $running] = copyProofRuns()->all();
        app(OperationStore::class)->change($ended, ['status' => OperationStatus::Failed, 'finished_at' => now()->subMinutes(10), 'lock_key' => null]);
        Operation::query()->where('kind', 'instance.create')->update(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
        Queue::fake();

        app(ProofRunWatch::class)->sweep();

        Queue::assertPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $ended->id);
        Queue::assertNotPushed(CancelProofGroup::class, static fn (CancelProofGroup $job): bool => $job->operationId === $running->id);
    });
});

/** The default's seed as a refresh records it, so the pool has a current seed to make warm copies from. */
function copyProofSeedTaken(?CarbonImmutable $at = null): void
{
    InstanceRuntime::query()->whereHas('instance', static fn ($instance) => $instance->where('name', 'default'))->firstOrFail()
        ->update(['seed_taken_at' => $at ?? now()->subHour()->startOfSecond()]);
}

/** The copy of a warm group as its create and completion leave it: active. */
function copyProofWarmActive(ProofWarmCopy $warm): Instance
{
    $copy = Instance::query()->where('task_workspace_owner_id', $warm->task_id)->firstOrFail();
    $copy->update(['status' => InstanceState::Active, 'first_setup_pending' => false, 'branch' => $copy->name, 'starting_commit' => str_repeat('c', 40)]);
    InstanceRuntime::query()->where('instance_id', $copy->id)->update(['power_state' => InstanceRuntimePowerState::Running]);
    Operation::query()->where('target_type', 'instance')->where('target_id', $copy->id)->update(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);

    return $copy->refresh();
}

/**
 * The copies of the groups as their destroy leaves them: gone.
 *
 * @param  list<int>  $groups
 */
function copyProofCopiesGone(array $groups): void
{
    Operation::query()->where('kind', 'tasks.cancel')->update(['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'lock_key' => null]);
    $copies = Instance::query()->whereIn('task_workspace_owner_id', $groups)->pluck('id');
    InstanceRuntime::query()->whereIn('instance_id', $copies)->delete();
    Instance::query()->whereKey($copies)->delete();
}

/** @return Collection<int, Operation> */
function copyProofGroupCancels(): Collection
{
    return Operation::query()->where('kind', 'tasks.cancel')->orderBy('id')->get();
}

describe('proof:run in copies: the warm pool', function (): void {
    beforeEach(function (): void {
        copyProofSeedTaken();
        config(['proof.warm_copies' => 2]);
    });

    it('makes proof.warm_copies warm copies on free places from the current seed, and each holds its place', function (): void {
        $groups = app(ProofWarmPool::class)->keep();
        $node = Node::query()->where('name', 'beast')->firstOrFail();
        $copies = app(ProofCopies::class);

        expect($groups)->toHaveCount(2)
            ->and(ProofWarmCopy::query()->pluck('task_id')->all())->toBe($groups)
            ->and(ProofWarmCopy::query()->pluck('seed_commit')->unique()->all())->toBe([str_repeat('c', 40)])
            ->and(Operation::query()->where('kind', 'instance.create')->where('status', '!=', 'succeeded')->count())->toBe(2)
            // Three places, two held by warm copies: one is free for a new copy, and the warm copies are not ready yet.
            ->and($copies->freePlaces($this->project, $copies->read()))->toBe([$node->id => 1])
            ->and($copies->places($this->project, $copies->read()))->toBe([$node->id => 1])
            // A full pool makes no more.
            ->and(app(ProofWarmPool::class)->keep())->toBe([]);
    });

    it('is kept by each reconcile pass, so the pool refills after use and after a seed refresh', function (): void {
        app()->call([new ReconcileSwampOperations, 'handle']);

        expect(ProofWarmCopy::query()->count())->toBe(2);
    });

    it('drains and resumes over the API for a caller with Gateway access, and answers the warm and the running proof copies', function (): void {
        app(ProofWarmPool::class)->keep();
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create(['name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
            'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '10.44.0.1', 'user' => 'orbit']));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
        $warm = Instance::query()->whereIn('task_workspace_owner_id', ProofWarmCopy::query()->pluck('task_id'))->orderBy('id')->pluck('id')->all();

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1'])->postJson('/api/v1/proof/pool/drain')->assertOk()
            ->assertJsonPath('data.drained', true)->assertJsonPath('data.warm_copies', $warm)->assertJsonPath('data.proof_copies', []);
        expect(app(ProofWarmPool::class)->drained())->toBeTrue();

        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.1'])->postJson('/api/v1/proof/pool/resume')->assertOk()->assertJsonPath('data.drained', false);
        // A Node without Gateway access is refused.
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.209'])->postJson('/api/v1/proof/pool/drain')->assertForbidden();
        expect(app(ProofWarmPool::class)->drained())->toBeFalse();
    });

    it('makes none with proof.warm_copies at 0', function (): void {
        config(['proof.warm_copies' => 0]);

        expect(app(ProofWarmPool::class)->keep())->toBe([])->and(ProofWarmCopy::query()->count())->toBe(0);
    });

    it('counts a warm copy against the task copy cap of its Node', function (): void {
        config(['orbit.tasks.copies_max' => 1]);
        copyProofOtherCopies(1);

        expect(app(ProofWarmPool::class)->keep())->toBe([])->and(ProofWarmCopy::query()->count())->toBe(0);
    });

    it('lets a proof take ready warm copies first, starts no new copy for them and refills the pool after', function (): void {
        app(ProofWarmPool::class)->keep();
        $warm = ProofWarmCopy::query()->orderBy('id')->get();
        $copies = $warm->map(copyProofWarmActive(...));
        $creates = Operation::query()->where('kind', 'instance.create')->count();

        $proof = copyProofAdmitted();
        $runs = copyProofRuns();

        expect($proof->status)->toBe(OperationStatus::Running)
            ->and($runs->pluck('input.instance_id')->sort()->values()->all())->toBe($copies->pluck('id')->sort()->values()->all())
            ->and($runs->pluck('target_id')->sort()->values()->all())->toBe($warm->pluck('task_id')->sort()->values()->all())
            ->and(Operation::query()->where('kind', 'instance.create')->count())->toBe($creates)
            ->and(ProofWarmCopy::query()->count())->toBe(0)
            ->and(Task::topLevel()->whereKey($runs->pluck('target_id'))->pluck('title')->every(static fn (string $title): bool => str_starts_with($title, 'Proof firewall:')))->toBeTrue()
            // The taken copy is active, so its run is due at once.
            ->and($runs->map(fn (Operation $run): string => app(ProofCopies::class)->state($run))->unique()->all())->toBe(['ready'])
            // Two places run the proof; the third takes one new warm copy.
            ->and(app(ProofWarmPool::class)->keep())->toHaveCount(1);
    });

    it('never gives a proof a warm copy whose create has not ended', function (): void {
        app(ProofWarmPool::class)->keep();
        $warm = ProofWarmCopy::query()->pluck('task_id');

        copyProofAdmitted();

        // One free place: one new copy, and no run in a copy that is not ready.
        expect(copyProofRuns())->toHaveCount(1)->and($warm->contains(copyProofRuns()->first()->target_id))->toBeFalse()
            ->and(ProofWarmCopy::query()->count())->toBe(2);
    });

    it('destroys and replaces a warm copy of an older seed, and a proof never takes one', function (): void {
        app(ProofWarmPool::class)->keep();
        ProofWarmCopy::query()->get()->each(copyProofWarmActive(...));
        $old = ProofWarmCopy::query()->pluck('task_id')->all();
        copyProofSeedTaken(now()->startOfSecond()->toImmutable());

        expect(app(ProofWarmPool::class)->takeable())->toBe([])
            ->and(app(ProofWarmPool::class)->take(Node::query()->where('name', 'beast')->value('id')))->toBeNull();
        app(ProofWarmPool::class)->keep();

        expect(copyProofGroupCancels()->pluck('target_id')->sort()->values()->all())->toBe($old)
            ->and(ProofWarmCopy::query()->whereIn('task_id', $old)->whereNotNull('dropped_at')->count())->toBe(2)
            // The places of the old copies stay held until their destroy ends: the one free place takes one new warm copy.
            ->and(ProofWarmCopy::query()->whereNull('dropped_at')->count())->toBe(1);

        // Once their copies are gone, their rows go and their places take new warm copies.
        copyProofCopiesGone($old);
        app(ProofWarmPool::class)->keep();

        expect(ProofWarmCopy::query()->whereIn('task_id', $old)->count())->toBe(0)->and(ProofWarmCopy::query()->count())->toBe(2);
    });

    it('makes no warm copy when a drain lands during the reads of its refill', function (): void {
        // The refill reads the default's Node (its ID map) before its create transaction; the drain lands during that read.
        app()->instance(SshExecutor::class, new class($this->ssh) implements SshExecutor
        {
            private bool $drained = false;

            public function __construct(private SshExecutor $inner) {}

            public function execute(SshConnection $connection, RemoteCommand $command): CommandResult
            {
                if (! $this->drained && str_contains((string) $command->input, 'ID map facts')) {
                    $this->drained = true;
                    app(ProofWarmPool::class)->drain();
                }

                return $this->inner->execute($connection, $command);
            }
        });

        expect(app(ProofWarmPool::class)->keep())->toBe([])->and(ProofWarmCopy::query()->count())->toBe(0)
            ->and(app(ProofWarmPool::class)->drained())->toBeTrue();
    });

    it('makes no warm copy while a proof waits for its admission, so the proof takes the free places first', function (): void {
        $node = Node::query()->where('name', 'beast')->firstOrFail();
        copyProofCiNode($node, 3, busy: 3);
        $proof = copyProofAdmitted();
        // The places come free; the proof's retry has not run yet.
        copyProofCiNode($node, 3);

        expect($proof->status)->toBe(OperationStatus::Waiting)
            ->and(app(ProofWarmPool::class)->keep())->toBe([])->and(ProofWarmCopy::query()->count())->toBe(0);

        app(ProofRunIntent::class)->admit($proof->id);

        expect($proof->refresh()->status)->toBe(OperationStatus::Running)->and(app(ProofWarmPool::class)->keep())->not->toBe([]);
    });

    it('drains in one call: destroys the idle warm copies, starts none until the resume, and leaves a running proof alone', function (): void {
        app(ProofWarmPool::class)->keep();
        $warm = ProofWarmCopy::query()->orderBy('id')->get();
        copyProofWarmActive($warm->first());
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        $idle = ProofWarmCopy::query()->pluck('task_id')->all();

        $drained = app(ProofWarmPool::class)->drain();

        // The warm copy whose create still runs is cancelled at a later keep(), once its create has ended.
        $creating = ProofWarmCopy::query()->where('task_id', '!=', $warm->first()->task_id)->value('task_id');
        expect($drained)->toBe($idle)
            ->and(copyProofGroupCancels()->pluck('target_id')->all())->toBe(array_values(array_diff($idle, [$creating])))
            ->and(copyProofGroupCancels()->pluck('target_id'))->not->toContain($run->target_id)
            ->and(app(ProofWarmPool::class)->drained())->toBeTrue()
            ->and(app(ProofWarmPool::class)->keep())->toBe([])
            ->and(ProofWarmCopy::query()->whereNull('dropped_at')->count())->toBe(0);
        copyProofWarmActive(ProofWarmCopy::query()->where('task_id', $creating)->firstOrFail());
        app(ProofWarmPool::class)->keep();
        expect(copyProofGroupCancels()->pluck('target_id')->sort()->values()->all())->toBe($idle);

        copyProofCopiesGone($idle);
        $resumed = app(ProofWarmPool::class)->resume();

        expect(app(ProofWarmPool::class)->drained())->toBeFalse()->and($resumed)->not->toBe([])
            ->and(ProofWarmCopy::query()->pluck('task_id')->all())->toBe($resumed);
    });
});
