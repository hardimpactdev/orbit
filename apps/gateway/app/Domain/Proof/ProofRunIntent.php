<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Actions\Operations\StartOperationAction;
use App\Actions\Tasks\CreateTaskGroupAction;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCopyStart;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskVmRemoved;
use App\Domain\Tasks\TaskWait;
use App\Jobs\Proof\AdmitProofRun;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Admits `proof:run`: the request records the proof; the job checks that the commit is trusted, selects the cases from the diff and the
 * selection list of the commit. A proof Project whose tasks run as copies proves in copies of its default: one in each free CI place on
 * every CI Node, balanced by measured time, each started only after the trust check (docs/cli/proof/run.mdx#the-run). A proof Project whose tasks
 * do not run as copies is refused with the error of the removed task VM runtime. The Gateway reads GitHub and fetches no code; the Swamp
 * parent `operation-proof-run-copy` of each run does the work. See docs/cli/proof/run.mmd.
 */
final readonly class ProofRunIntent
{
    /** Why a selected cases file that holds no case yet is listed as uncovered (docs/reference/feature-flow.md#choose-the-cases). */
    private const string NoCaseYet = 'none: no case yet';

    /** The total admission budget, including capacity waits. */
    public const int WaitSeconds = 7200;

    public const int RetrySeconds = 20;

    public function __construct(private ProofCommitTrust $trust, private ProofCommitSource $source, private ProofSettings $settings,
        private OperationIdempotency $idempotency, private OperationStore $store, private ProofGroup $groups) {}

    /**
     * Records the proof and queues its admission, then answers. The request does one insert; the checks of the commit, the reads of GitHub,
     * the groups and copies and the hand-over to Swamp run in the job {@see AdmitProofRun}, which a release drains with the queue workers
     * (docs/cli/proof/run.mmd). The outcome of a refused or failed admission is on the proof Operation, with its code and message.
     */
    public function start(string $commit, bool $all, string $caller, ?string $requestId, bool $reportOnly = false, ?Node $authenticatedCaller = null, ?string $base = null): Operation
    {
        if (($existing = $this->idempotency->replay(OperationKinds::ProofRun)) !== null) {
            return $existing;
        }
        $swamp = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($roles) => $roles
            ->where('role', RoleName::Swamp)->where('status', LifecycleStatus::Active))->first()
            ?? throw new ResourceOperationException('proof.swamp_unavailable', 'No active Swamp Node can run a proof.', 503);
        // The proof the CLI follows closes once, when the last of its runs has ended. Each run is an Operation of its own on its own task group.
        $proof = $this->store->create(OperationKinds::ProofRun, 'node', $swamp->id, $swamp->id,
            ['commit' => $commit, 'all' => $all, 'report_only' => $reportOnly, ...($base === null ? [] : ['base' => $base]),
                'caller_node_id' => $authenticatedCaller?->id,
                'ci_pool' => $authenticatedCaller?->status === LifecycleStatus::Active && $authenticatedCaller->roles()
                    ->where('role', RoleName::CiRunner)->where('status', LifecycleStatus::Active)->exists(),
                'runs' => []], $caller, $requestId);
        $this->store->change($proof, ['status' => OperationStatus::Running]);
        try {
            app(Dispatcher::class)->dispatch(new AdmitProofRun($proof->id));
        } catch (Throwable $exception) {
            // The queue refused the job: end the proof with a code instead of leaving it for the watchdog.
            report($exception);
            $this->fail($proof, 'proof.queue_unavailable', 'The Gateway could not queue the admission of the proof. Run it again.', []);
        }

        return $proof->refresh();
    }

    /** The admission of a proof, in a queue job: it checks the commit, selects the cases and records and hands over one run for each. */
    public function admit(int $proofId): void
    {
        $proof = Operation::query()->find($proofId);
        if (! $proof instanceof Operation || $proof->kind !== OperationKinds::ProofRun || ! in_array($proof->status, [OperationStatus::Running, OperationStatus::Waiting], true) || $proof->input['runs'] !== []) {
            return;
        }
        $input = ValidatedData::object($proof->input);
        $commit = ValidatedData::string($input['commit']);
        $uncovered = [];
        $waitStep = ProofCopies::WaitStep;
        try {
            if (array_key_exists('batches', $input)) {
                // A retry after a wait for a place: the first pass checked the commit, selected the cases and planned the batches, and
                // recorded them, so a retry reads GitHub no more and keeps that selection (docs/cli/proof/run.mmd).
                $intent = $input;
                $uncovered = self::storedUncovered($input['uncovered'] ?? null);
                $batches = ProofBatchPlan::stored($input['batches']);
                $this->assertExecutionEnabled();
            } else {
                $this->trust->assert($commit);
                $selection = isset($input['base'])
                    ? $this->selectRange(ValidatedData::string($input['base']), $commit)
                    : $this->select($commit, ($input['all'] ?? false) === true);
                $uncovered = $selection->uncovered;
                $intent = [...$input, 'everything_because' => $selection->everythingBecause, 'cases' => $selection->cases, 'selected_commands' => array_values(array_unique(array_column($selection->cases, 'command'))), 'uncovered' => $selection->uncovered];
                // A proof that was cancelled or ended as lost while the job read GitHub stays as it is.
                if (! $this->whileOpen($proof->id, fn (Operation $current) => $current->input['runs'] !== [] ? null : $this->store->change($current, ['input' => $intent]))) {
                    return;
                }
                if ($selection->nothingToProve()) {
                    $this->whileOpen($proof->id, fn (Operation $current) => ValidatedData::object($current->input)['runs'] !== [] ? null : $this->store->change($current, ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => [
                        'commit' => $commit, 'outcome' => 'nothing_to_prove', 'reason' => 'proof.nothing_to_prove', 'cases' => [],
                        'uncovered' => $selection->uncovered, 'receipt_url' => null,
                    ]]));

                    return;
                }
                $this->assertExecutionEnabled();
                // Record all children together, after the trust check above: only then may a copy, which holds the nested fleet's Club key, be made.
                $batches = app(ProofBatchPlan::class)->read($commit, $selection->cases);
                $intent = [...$intent, 'batches' => $batches];
                if (! $this->whileOpen($proof->id, fn (Operation $current) => $current->input['runs'] !== [] ? null : $this->store->change($current, ['input' => $intent]))) {
                    return;
                }
            }
            // A missing proof Project has no default to copy either: the same refusal names what to do.
            $project = Project::query()->find($this->settings->projectId());
            if (! $project instanceof Project || ! ProofCopies::runs($project)) {
                throw TaskVmRemoved::refusal();
            }
            $this->admitCopies($proof, $commit, $intent, $batches, $project, $waitStep);
        } catch (Throwable $exception) {
            if ($exception instanceof TaskCapacityException && $this->waitForRoom($proof, $exception->getMessage(), $waitStep)) {
                return;
            }
            if ($exception instanceof TaskCapacityException) {
                $exception = new ResourceOperationException('proof.capacity_timeout', 'No proof place became free within the two-hour admission budget.', 409);
            }
            $this->fail($proof, $exception instanceof ResourceOperationException ? $exception->errorCode : ProofCommandProjection::RunFailed,
                $exception instanceof ResourceOperationException ? $exception->getMessage() : 'The Gateway could not admit the proof.', $uncovered);
            if (! $exception instanceof ResourceOperationException) {
                report($exception);
            }
        }
    }

    /**
     * The proof, locked, when its admission may go on; null when it already names its runs. It refuses a proof that ended, waits behind
     * the working proof and earlier proofs, and fails at the end of the two-hour budget. Call it in the admission transaction.
     */
    private function openForAdmission(Operation $proof, string &$waitStep): ?Operation
    {
        // The watchdog may have ended this proof as lost while the job was slow. Then nothing is admitted, so no run is orphaned.
        // SQLite takes the write lock at BEGIN (transaction_mode IMMEDIATE), so the watchdog sees none of this admission until it commits.
        $current = Operation::query()->lockForUpdate()->find($proof->id);
        if (! $current instanceof Operation || $current->status->terminal()) {
            throw new ResourceOperationException(ProofRunSettlement::AdmissionInterrupted, 'The proof was ended while it was admitted. Run it again.', 409);
        }

        if ($current->input['runs'] !== []) {
            return null;
        }
        if ($current->created_at?->lt(now()->subSeconds(self::WaitSeconds))) {
            throw new ResourceOperationException('proof.capacity_timeout', 'The two-hour admission budget expired before a proof place was claimed.', 409);
        }
        if (($working = ProofRunSettlement::workingProof()) !== null) {
            $waitStep = "Waiting for proof Operation {$working->id} to finish.";
            throw new TaskCapacityException(TaskWait::Capacity, $waitStep);
        }
        // Expired parents cannot keep the queue closed if their next retry was lost.
        if (Operation::query()->where('kind', OperationKinds::ProofRun)->whereNotIn('status', OperationStatus::terminalValues())
            ->whereNull('cancel_requested_at')->whereJsonLength('input->runs', 0)->where('id', '<', $proof->id)
            ->where('created_at', '>=', now()->subSeconds(self::WaitSeconds))->exists()) {
            throw new TaskCapacityException(TaskWait::Capacity, 'Waiting behind an earlier proof.');
        }

        return $current;
    }

    /**
     * The run list commits with the groups. A job that stops after the commit leaves a proof that names its runs.
     *
     * @param  array<string,mixed>  $intent
     * @param  list<array<string,mixed>>  $runs
     * @param  list<array<string,mixed>>  $batches
     */
    private function recordRuns(Operation $proof, Operation $current, array $intent, array $runs, array $batches): void
    {
        $state = $current->state ?? [];
        unset($state['capacity_wait_reason']);
        $this->store->change($proof, ['status' => OperationStatus::Running, 'input' => [...$intent, 'runs' => $runs, 'batches' => $batches], 'started_at' => now(), 'current_step' => null, 'state' => $state]);
    }

    /**
     * Admits the runs of a proof in copies, after the trust check: the free CI places on every Node, the shards balanced over them, and
     * for each shard its group, the copy start of that group on the Node of its place and the run, which the Gateway holds until the
     * copy is active (ProofChildStarts). The reads of Prometheus, the default and each Node run before the transaction.
     *
     * @param  array<string,mixed>  $intent
     * @param  list<array{command:string,file:string,batch:string,cases:list<string>,seconds:float}>  $batches
     */
    private function admitCopies(Operation $proof, string $commit, array $intent, array $batches, Project $project, string &$waitStep): void
    {
        $waitStep = ProofCopies::WaitStep;
        $copies = app(ProofCopies::class);
        $placeRead = $copies->read();
        // A proof with no free place waits without reading the default and the Nodes; the transaction counts the places again.
        if ($copies->places($project, $placeRead) === []) {
            throw new TaskCapacityException(TaskWait::Capacity, 'Waiting for a free CI place on a CI Node.');
        }
        $read = app(TaskCopyStart::class)->read($project, array_keys($copies->places($project, $placeRead)));
        DB::transaction(function () use ($proof, $commit, $intent, $batches, $project, $read, $placeRead, $copies, &$waitStep): void {
            $current = $this->openForAdmission($proof, $waitStep);
            if ($current === null) {
                return;
            }
            $waitStep = ProofCopies::WaitStep;
            // Counted again under the lock, on the Nodes whose ID map was read.
            $places = array_intersect_key($copies->places($project, $placeRead), $read['hosts']);
            if ($places === []) {
                throw new TaskCapacityException(TaskWait::Capacity, 'Waiting for a free CI place on a CI Node.');
            }
            $shards = ProofBatchPlan::split($batches, array_sum($places));
            // A shard that cannot finish within the longest shard budget waits for more places while more can come.
            if (array_sum($places) < $copies->mostPlaces($project, $placeRead) && array_any($shards, ProofBatchPlan::overBudget(...))) {
                throw new TaskCapacityException(TaskWait::Capacity, 'Waiting for more free CI places: on '.count($shards).' place(s) a shard would run longer than '.ProofBatchPlan::MaxShardSeconds.' seconds.');
            }
            $nodes = Node::query()->findMany(array_keys($places))->keyBy('id');
            $runs = [];
            foreach (ProofCopies::spread($places, count($shards)) as $index => $nodeId) {
                $assignments = $shards[$index];
                $case = $assignments[0];
                // A warm copy of the Node first: its create has ended. Else a new copy from the Node's seed.
                $group = app(ProofWarmPool::class)->take($nodeId);
                $group?->update(self::groupText($commit, $case['command']));
                $copy = $group !== null ? Instance::query()->findOrFail($group->taskable_id)
                    : Instance::query()->findOrFail(app(TaskCopyStart::class)->admitRead($group = $this->group($commit, $case['command']), ProofGroup::Trigger, null, $read, $nodes->get($nodeId))->target_id);
                $stored = ProofBatchPlan::stored($assignments);
                $run = $this->groups->admit($group, 'proof.command', fn (): Operation => app(StartOperationAction::class)->execute('proof.command', 'task_group', $group->id, $copy->node_id, [
                    'proof_operation_id' => $proof->id, 'commit' => $commit, 'command' => $case['command'], 'file' => $case['file'], 'copy' => true,
                    'assignments' => $stored, 'run_budget_seconds' => ProofBatchPlan::budget($stored),
                    'caller_node_id' => $proof->input['caller_node_id'] ?? null, 'ci_pool' => ($proof->input['ci_pool'] ?? false) === true,
                    'group_id' => $group->id, 'instance_id' => $copy->id, 'node_id' => $copy->node_id,
                ], ProofGroup::Trigger, null));
                $runs[] = ['command' => $case['command'], 'file' => $case['file'], 'group_id' => $group->id, 'instance_id' => $copy->id, 'operation_id' => $run->id, 'assignments' => $assignments];
            }
            $this->recordRuns($proof, $current, $intent, $runs, $batches);
        });
    }

    private function assertExecutionEnabled(): void
    {
        if (! $this->settings->executionEnabled()) {
            throw new ResourceOperationException('proof.execution_disabled', 'Proof execution is switched off: the commit is trusted and the cases are selected, but nothing runs.', 409);
        }
    }

    /**
     * The uncovered paths that the first pass recorded with its selection.
     *
     * @return list<array{path:string,reason:string}>
     */
    private static function storedUncovered(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException('The recorded uncovered paths must be a list.');
        }

        return array_map(static function (mixed $row): array {
            $row = ValidatedData::stringMap($row);

            return ['path' => ValidatedData::string($row['path'] ?? null), 'reason' => ValidatedData::string($row['reason'] ?? null)];
        }, $value);
    }

    /** A held proof reads `waiting` until admission makes it `running`; a proof with no free CI place waits within the total admission budget.
     * @return bool whether another admission attempt was queued
     */
    private function waitForRoom(Operation $proof, string $reason, string $step): bool
    {
        if ($proof->created_at === null || $proof->created_at->lt(now()->subSeconds(self::WaitSeconds))) {
            return false;
        }
        try {
            // A proof that was cancelled while the job ran is left alone, and nothing is released or cancelled for it.
            if (! $this->whileOpen($proof->id, static fn () => null)) {
                return false;
            }
            $this->whileOpen($proof->id, fn (Operation $current) => $this->store->change($current, ['status' => OperationStatus::Waiting, 'current_step' => $step, 'state' => [...($current->state ?? []), 'capacity_wait_reason' => $reason]]));
            app(Dispatcher::class)->dispatch(new AdmitProofRun($proof->id)->delay(self::RetrySeconds));
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }

        return true;
    }

    /**
     * Ends a proof that was refused or failed at admission. A proof that already names its runs is left to its runs and the watchdog:
     * they closed the groups, and ending the proof here would leave them unsettled. The code and the message are on the Operation, as the request carried them before.
     *
     * @param  list<array{path:string,reason:string}>  $uncovered
     */
    private function fail(Operation $proof, string $code, string $message, array $uncovered): void
    {
        $this->whileOpen($proof->id, fn (Operation $current) => ValidatedData::object($current->input)['runs'] !== [] ? null : $this->store->change($current, ['status' => OperationStatus::Failed, 'finished_at' => now(), 'error_code' => $code, 'current_step' => null,
            'state' => [...($current->state ?? []), 'native_failure' => ['failed_step' => null, 'detail' => $message, 'stderr_recorded' => false]],
            'result' => ['commit' => ValidatedData::object($current->input)['commit'] ?? null, 'outcome' => 'failed', 'cases' => [],
                'selected_commands' => ValidatedData::stringList(ValidatedData::object($current->input)['selected_commands'] ?? []),
                'uncovered' => $uncovered, 'receipt_url' => null]]));
    }

    /**
     * Writes to the proof only while it is open, under its row lock, so a proof that was cancelled or ended as lost is never revived.
     *
     * @param  Closure(Operation): mixed  $write
     * @return bool whether the proof was still open
     */
    private function whileOpen(int $id, Closure $write): bool
    {
        return DB::transaction(function () use ($id, $write): bool {
            $current = Operation::query()->lockForUpdate()->find($id);
            if (! $current instanceof Operation || $current->status->terminal()) {
                return false;
            }
            $write($current);

            return true;
        });
    }

    /**
     * The title and brief of a proof run's group: a new group's, and a taken warm copy's group's from then on.
     *
     * @return array{title: string, brief: string}
     */
    private static function groupText(string $commit, string $command): array
    {
        return ['title' => 'Proof '.$command.' '.substr($commit, 0, 12), 'brief' => 'Prove '.$command.' at commit '.$commit.'.'];
    }

    private function group(string $commit, string $command): Task
    {
        $text = self::groupText($commit, $command);

        return app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData($this->settings->projectId(), $text['title'], $text['brief'], TaskGroupStatus::Todo, [new TaskInputData('Run the proof cases',
            'The Swamp workflow operation-proof-run-copy proves the command in its own copy.', [['id' => 'verdict', 'type' => 'review', 'description' => 'Every selected case passes.']])]));
    }

    /**
     * @param  array{command:string,file:string,only?:list<string>,reasons?:array<string,string>}  $earlier
     * @param  array{command:string,file:string,only?:list<string>,reasons?:array<string,string>}  $later
     * @return array{command:string,file:string,only?:list<string>,reasons?:array<string,string>}
     */
    private static function unionCase(array $earlier, array $later): array
    {
        if (! isset($earlier['only']) || ! isset($later['only'])) {
            return ['command' => $later['command'], 'file' => $later['file'], 'reasons' => ['*' => 'selected by an intervening commit']];
        }

        return ['command' => $later['command'], 'file' => $later['file'],
            'only' => array_values(array_unique([...$earlier['only'], ...$later['only']])),
            'reasons' => [...($earlier['reasons'] ?? []), ...($later['reasons'] ?? [])]];
    }

    private function selectRange(string $base, string $commit): ProofSelectionResult
    {
        try {
            $history = $this->source->history($base, $commit)
                ?? throw new ResourceOperationException('proof.base_not_ancestor', 'The proof base must be an ancestor of the tested commit.', 409);
            $wanted = [];
            $uncovered = [];
            $everything = null;
            foreach ($history as $sha => $changes) {
                $selection = $this->select($sha, false, $changes);
                $everything ??= $selection->everythingBecause;
                foreach ($selection->uncovered as $row) {
                    $uncovered[$row['path'].':'.$row['reason']] = $row;
                }
                foreach ($selection->cases as $case) {
                    $file = $case['file'];
                    $wanted[$file] = isset($wanted[$file]) ? self::unionCase($wanted[$file], $case) : $case;
                }
            }
            $current = ProofSelection::casesFiles($this->source->proofPaths($commit));
            $selected = [];
            foreach ($wanted as $file => $case) {
                if (! in_array($file, $current, true)) {
                    $uncovered[$file] = ['path' => $file, 'reason' => 'gone at tested SHA'];

                    continue;
                }
                $spec = ValidatedData::object(Yaml::parse($this->source->file($commit, $file) ?? ''));
                $case['command'] = ValidatedData::string($spec['command'] ?? null);
                $ids = array_keys(ProofBatchPlan::population($spec));
                if (isset($case['only'])) {
                    foreach (array_diff($case['only'], $ids) as $gone) {
                        $uncovered[$file.'#'.$gone] = ['path' => $file.'#'.$gone, 'reason' => 'gone at tested SHA'];
                    }
                    $case['only'] = array_values(array_intersect($ids, $case['only']));
                    $case['reasons'] = array_intersect_key($case['reasons'] ?? [], array_flip($case['only']));
                    if ($case['only'] === []) {
                        continue;
                    }
                } elseif ($ids === []) {
                    // The file is there but holds no case yet at the tested commit.
                    $uncovered[$file] = ['path' => $file, 'reason' => self::NoCaseYet];

                    continue;
                }
                $selected[] = $case;
            }

            return new ProofSelectionResult($selected, array_values($uncovered), $everything, $base);
        } catch (GitHubApiException $exception) {
            throw ProofGitHubRefusal::from($exception, 'the cases cannot be selected');
        } catch (ParseException) {
            throw new ResourceOperationException('proof.selection_invalid', 'Malformed proof YAML.', 409);
        }
    }

    private function select(string $commit, bool $all, ?ProofChangedFiles $changes = null): ProofSelectionResult
    {
        try {
            $changes ??= $this->source->changes($commit);
            $list = $this->source->file($commit, ProofSelectionList::Path);
            $casesFiles = ProofSelection::casesFiles($this->source->proofPaths($commit));
        } catch (GitHubApiException $exception) {
            throw ProofGitHubRefusal::from($exception, 'the cases cannot be selected');
        }

        $selection = ProofSelection::select($list, $changes, $all, $casesFiles);
        try {
            $batchPath = 'swamp/proof/batches.yaml';
            $candidates = $selection->cases;
            if (in_array($batchPath, $changes->paths(), true)) {
                foreach (array_diff($casesFiles, $selection->files()) as $file) {
                    $spec = Yaml::parse($this->source->file($commit, $file) ?? '');
                    $candidates[] = ['command' => ValidatedData::string(ValidatedData::object($spec)['command'] ?? null), 'file' => $file];
                }
            }
            if ($candidates === []) {
                return $selection;
            }
            $result = $this->source->caseSelection($changes->mergeBase, $commit);
            $rules = $list === null ? null : ProofSelectionList::parse($list);
            $selected = [];
            $uncovered = $selection->uncovered;
            foreach ($candidates as $candidate) {
                // A cases file that holds no case yet selects no command, however it was selected; it is listed as uncovered.
                if (self::holdsNoCase($this->source->file($commit, $candidate['file']))) {
                    $uncovered[] = ['path' => $candidate['file'], 'reason' => self::NoCaseYet];

                    continue;
                }
                $codeChanged = $selection->everythingBecause !== null || array_any($changes->paths(), static function (string $path) use ($rules, $candidate, $batchPath): bool {
                    $rule = $rules?->match($path);

                    return $path !== $candidate['file'] && $path !== $batchPath && $rule?->kind === ProofRuleKind::Commands && in_array($candidate['command'], $rule->commands, true);
                });
                // The runner also decides code changes, including explicit execution-coverage gaps.
                // Only an all-cases request or uncertain path inventory overrides its validated result.
                $ids = $selection->everythingBecause !== null ? null : ProofCaseSelection::select($result, $changes->mergeBase, $commit, $candidate);
                if ($ids === null) {
                    $selected[] = [...$candidate, 'reasons' => ['*' => $codeChanged ? 'command code changed' : 'selection uncertain']];
                } elseif ($ids !== []) {
                    $selected[] = [...$candidate, 'only' => array_keys($ids), 'reasons' => $ids];
                }
            }

            return new ProofSelectionResult($selected, $uncovered, $selection->everythingBecause, $selection->mergeBase);
        } catch (ParseException) {
            throw new ResourceOperationException('proof.selection_invalid', 'Malformed proof YAML.', 409);
        } catch (GitHubApiException $exception) {
            throw ProofGitHubRefusal::from($exception, 'the cases cannot be selected');
        }
    }

    /** Whether a cases file states `cases: []`: it has no case yet. Any other shape is for the batch plan to accept or refuse. */
    private static function holdsNoCase(?string $file): bool
    {
        $spec = Yaml::parse($file ?? '');

        return is_array($spec) && array_key_exists('cases', $spec) && $spec['cases'] === [];
    }
}
