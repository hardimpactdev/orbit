<?php

declare(strict_types=1);

use App\Actions\Operations\CancelOperationAction;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\GitHub\GitHubReview;
use App\Domain\Instances\ContainerDestroyExecutionCopies;
use App\Domain\Instances\InstanceRuntimePowerState;
use App\Domain\Instances\InstanceState;
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
use App\Domain\Proof\ProofGroup;
use App\Domain\Proof\ProofRunIntent;
use App\Domain\Proof\ProofRunWatch;
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
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Route;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
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
    Http::fake(['api.github.com/repos/nckrtl/trial' => Http::response(['full_name' => 'nckrtl/trial', 'private' => false])]);
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
    config(['proof.project' => $this->project->id, 'proof.execution' => true, 'proof.copies' => 2, 'orbit.tasks.copies_max' => 8]);
});

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

    it('starts one copy per shard, at most two, and a held run for each that names its copy', function (): void {
        $proof = copyProofAdmitted();
        $runs = copyProofRuns();
        $copies = Instance::query()->whereNotNull('task_workspace_owner_id')->get();

        expect($proof->status)->toBe(OperationStatus::Running)
            ->and($runs)->toHaveCount(2)->and($copies)->toHaveCount(2)
            ->and(Operation::query()->where('kind', 'instance.create')->whereIn('target_id', $copies->pluck('id'))->count())->toBe(2)
            ->and($runs->every(static fn (Operation $run): bool => $run->input['copy'] === true && $copies->contains('id', $run->input['instance_id'])))->toBeTrue()
            ->and(collect($proof->input['runs'])->pluck('instance_id')->sort()->values()->all())->toBe($copies->pluck('id')->sort()->values()->all())
            ->and(app(DirectOperation::class)->workflowFor($runs->first()))->toBe('operation-proof-run-copy')
            ->and(Task::topLevel()->whereKey($runs->pluck('target_id'))->get()->every(static fn (Task $group): bool => $group->status === TaskGroupStatus::Reserved))->toBeTrue();
    });

    it('leaves two copy places for task groups: one shard with one place, and a wait with none', function (int $max, int $runs, OperationStatus $status): void {
        config(['orbit.tasks.copies_max' => $max]);
        $proof = copyProofAdmitted();

        expect($proof->status)->toBe($status)->and(copyProofRuns())->toHaveCount($runs)
            ->and(Instance::query()->whereNotNull('task_workspace_owner_id')->count())->toBe($runs);
        if ($runs === 0) {
            expect($proof->current_step)->toBe('Wait for a free copy place');
        }
    })->with([
        'eight places: two copies' => [8, 2, OperationStatus::Running],
        'three places: one copy' => [3, 1, OperationStatus::Running],
        'two places: none, it waits' => [2, 0, OperationStatus::Waiting],
    ]);
});

/** Task copies of other groups on the default's Node: each counts as a live copy. */
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

describe('proof:run in copies: the shard budget', function (): void {
    it('waits for another copy place rather than plan a shard past the longest shard budget, and runs it once it has every place it may take', function (): void {
        // Measured seconds: both batches on one copy take (600 + 2 x 180 + 6,000) x 1.5 s, more than one shard may take.
        $default = Instance::query()->where('name', 'default')->firstOrFail();
        Operation::query()->create(['kind' => 'proof.command', 'target_type' => 'node', 'target_id' => $default->node_id, 'node_id' => $default->node_id,
            'status' => OperationStatus::Succeeded, 'input' => [], 'result' => ['cases' => [
                ['program' => 'swamp/proof/firewall/allow.cases.yaml#allow', 'seconds' => 3000], ['program' => 'swamp/proof/firewall/allow.cases.yaml#refused', 'seconds' => 3000]]]]);
        config(['orbit.tasks.copies_max' => 5]);
        copyProofOtherCopies(2);
        $proof = copyProofAdmitted();

        expect($proof->status)->toBe(OperationStatus::Waiting)->and($proof->current_step)->toBe('Wait for a free copy place')
            ->and($proof->state['capacity_wait_reason'])->toContain('Waiting for more copy places')
            ->and(copyProofRuns())->toHaveCount(0);

        // With as many places as a proof may take, it runs: here both places, one batch each.
        config(['orbit.tasks.copies_max' => 6]);
        app(ProofRunIntent::class)->admit($proof->id);
        expect($proof->refresh()->status)->toBe(OperationStatus::Running)->and(copyProofRuns())->toHaveCount(2);
    });
});

describe('proof:run in copies: the start waits for the copy', function (): void {
    it('holds a run while its copy is not active, and releases it on an active copy', function (): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        app(OperationStore::class)->change($run, ['state' => [...($run->state ?? []), 'proof_child_start' => ['not_before' => now()->subSecond()->toISOString(), 'released_at' => null, 'advanced_at' => null]]]);

        expect(app(ProofChildStarts::class)->release($run->refresh()))->toBeInstanceOf(CarbonImmutable::class);
        copyProofActive($run);
        expect(app(ProofChildStarts::class)->release($run->refresh()))->toBeTrue();
    });

    it('fails a run whose copy failed, with its cases unrun, and cancels its group', function (): void {
        copyProofAdmitted();
        $run = copyProofRuns()->first();
        Task::topLevel()->whereKey($run->input['group_id'])->update(['status' => TaskGroupStatus::Failed]);
        app(OperationStore::class)->change($run, ['state' => [...($run->state ?? []), 'proof_child_start' => ['not_before' => now()->subSecond()->toISOString(), 'released_at' => null, 'advanced_at' => null]]]);

        expect(app(ProofChildStarts::class)->release($run->refresh()))->toBeFalse();
        $run->refresh();
        expect($run->status)->toBe(OperationStatus::Failed)->and($run->error_code)->toBe(ProofCommandProjection::RunFailed)
            ->and(collect($run->result['cases'])->pluck('verdict')->unique()->all())->toBe(['unrun']);
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
        'completion failed' => [static function (Instance $copy): void {
            $copy->update(['status' => InstanceState::SourceResolved]);
            Operation::query()->create(['kind' => 'instance.complete', 'target_type' => 'instance', 'target_id' => $copy->id, 'node_id' => $copy->node_id,
                'input' => [], 'state' => [], 'status' => OperationStatus::Failed, 'error_code' => 'instance.complete_failed', 'finished_at' => now(), 'sequence' => 1, 'attempts' => 0]);
        }, 'failed'],
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
