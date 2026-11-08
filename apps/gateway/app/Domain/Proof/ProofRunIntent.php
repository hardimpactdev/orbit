<?php

declare(strict_types=1);

namespace App\Domain\Proof;

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
use App\Domain\Tasks\TaskCancelIntent;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionIntent;
use App\Domain\TaskVms\TaskVmSeedFloor;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Jobs\Proof\AdmitProofRun;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Admits `proof:run`: the request records the proof; the job checks that the commit is trusted, selects the cases from the diff and the
 * selection list of the commit, and records the intent of one run for each selected command, each on a task group and VM of its own, all
 * or none. The Gateway reads GitHub and fetches no code; the Swamp parent `operation-proof-run` of each run does the work. See docs/cli/proof/run.mmd.
 */
final readonly class ProofRunIntent
{
    /** The total admission budget, including capacity waits. */
    public const int WaitSeconds = 7200;

    public const int RetrySeconds = 20;

    public const string WaitStep = 'Wait for VMs to be released';

    public function __construct(private ProofCommitTrust $trust, private ProofCommitSource $source, private ProofSettings $settings,
        private OperationIdempotency $idempotency, private OperationStore $store, private ProofGroup $groups) {}

    /**
     * Records the proof and queues its admission, then answers. The request does one insert; the checks of the commit, the reads of GitHub,
     * the groups and VMs and the hand-over to Swamp run in the job {@see AdmitProofRun}, which a release drains with the queue workers
     * (docs/cli/proof/run.mmd). The outcome of a refused or failed admission is on the proof Operation, with its code and message.
     */
    public function start(string $commit, bool $all, string $caller, ?string $requestId, bool $reportOnly = false, bool $keepFailed = false, ?Node $authenticatedCaller = null): Operation
    {
        if (($existing = $this->idempotency->replay(OperationKinds::ProofRun)) !== null) {
            return $existing;
        }
        $swamp = Node::query()->where('status', LifecycleStatus::Active)->whereHas('roles', static fn ($roles) => $roles
            ->where('role', RoleName::Swamp)->where('status', LifecycleStatus::Active))->first()
            ?? throw new ResourceOperationException('proof.swamp_unavailable', 'No active Swamp Node can run a proof.', 503);
        // The proof the CLI follows closes once, when the last of its runs has ended. Each run is an Operation of its own on its own task group.
        $proof = $this->store->create(OperationKinds::ProofRun, 'node', $swamp->id, $swamp->id,
            ['commit' => $commit, 'all' => $all, 'report_only' => $reportOnly,
                'caller_node_id' => $authenticatedCaller?->id,
                'ci_pool' => $authenticatedCaller?->status === LifecycleStatus::Active && $authenticatedCaller->roles()
                    ->where('role', RoleName::CiRunner)->where('status', LifecycleStatus::Active)->exists(),
                'runs' => [], ...($keepFailed ? ['keep_failed' => true] : [])], $caller, $requestId);
        $this->store->change($proof, ['status' => OperationStatus::Running, 'started_at' => now()]);
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
        if (! $proof instanceof Operation || $proof->kind !== OperationKinds::ProofRun || $proof->status !== OperationStatus::Running || $proof->input['runs'] !== []) {
            return;
        }
        $input = ValidatedData::object($proof->input);
        $commit = ValidatedData::string($input['commit']);
        $uncovered = [];
        try {
            $this->trust->assert($commit);
            $selection = $this->select($commit, ($input['all'] ?? false) === true);
            $uncovered = $selection->uncovered;
            $intent = [...$input, 'everything_because' => $selection->everythingBecause, 'cases' => $selection->cases, 'selected_commands' => array_values(array_unique(array_column($selection->cases, 'command'))), 'uncovered' => $selection->uncovered];
            // A proof that was cancelled or ended as lost while the job read GitHub stays as it is.
            if (! $this->whileOpen($proof->id, fn (Operation $current) => $this->store->change($current, ['input' => $intent]))) {
                return;
            }
            if ($selection->nothingToProve()) {
                $this->whileOpen($proof->id, fn (Operation $current) => ValidatedData::object($current->input)['runs'] !== [] ? null : $this->store->change($current, ['status' => OperationStatus::Succeeded, 'finished_at' => now(), 'result' => [
                    'commit' => $commit, 'outcome' => 'nothing_to_prove', 'reason' => 'proof.nothing_to_prove', 'cases' => [],
                    'uncovered' => $selection->uncovered, 'receipt_url' => null,
                ]]));

                return;
            }
            if (! $this->settings->executionEnabled()) {
                throw new ResourceOperationException('proof.execution_disabled', 'Proof execution is switched off: the commit is trusted and the cases are selected, but nothing runs.', 409);
            }
            // One transaction holds every group, VM and run, so a command that cannot start (no host has room) leaves nothing behind:
            // the groups roll back with it, and Swamp starts no run before the whole proof is admitted.
            $fresh = $this->freshHosts($selection->mergeBase);
            app(TaskVmSeedFloor::class)->during($fresh->hosts, $fresh->reasons, fn () => DB::transaction(function () use ($proof, $commit, $selection, $intent): array {
                // The watchdog may have ended this proof as lost while the job was slow. Then nothing is admitted, so no run is orphaned.
                // SQLite takes the write lock at BEGIN (transaction_mode IMMEDIATE), so the watchdog sees none of this admission until it commits.
                if (Operation::query()->lockForUpdate()->find($proof->id)?->status->terminal() !== false) {
                    throw new ResourceOperationException(ProofRunSettlement::AdmissionInterrupted, 'The proof was ended while it was admitted. Run it again.', 409);
                }

                $runs = [];
                foreach ($selection->cases as $case) {
                    $group = $this->group($commit, $case['command']);
                    $run = $this->groups->admit($group, 'proof.command', fn (): Operation => app(TaskProvisionIntent::class)->admit($group, $commit, 'proof.command',
                        fn (array $input, Task $group, Instance $instance, TaskVm $vm): array => $this->runInput($proof, $commit, $selection->mergeBase, $case, $input, $group, $instance), 'proof-run', null,
                        ciPool: ($intent['ci_pool'] ?? false) === true));
                    $runs[] = ['command' => $case['command'], 'file' => $case['file'], 'group_id' => $group->id, 'operation_id' => $run->id];
                }

                // The run list commits with the groups. A job that stops after the commit leaves a proof that names its runs.
                $this->store->change($proof, ['input' => [...$intent, 'runs' => $runs], 'current_step' => null]);

                return $runs;
            }));
        } catch (Throwable $exception) {
            if ($exception instanceof TaskCapacityException && $this->waitForRoom($proof, $exception->getMessage())) {
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
     * The hosts whose ready copies include every change of what the image carries up to the merge base of the commit under proof. GitHub is read
     * here, before the admission transaction.
     */
    private function freshHosts(string $mergeBase): ProofHostFreshness
    {
        try {
            return app(ProofFreshHosts::class)->assess($mergeBase);
        } catch (GitHubApiException) {
            throw new ResourceOperationException('proof.github_unavailable', 'GitHub could not be read, so the age of the ready copies cannot be checked.', 503);
        }
    }

    /** A full pool or a stale base waits within the total admission budget.
     * @return bool whether another admission attempt was queued
     */
    private function waitForRoom(Operation $proof, string $reason): bool
    {
        if ($proof->created_at === null || $proof->created_at->lt(now()->subSeconds(self::WaitSeconds))) {
            return false;
        }
        try {
            // A proof that was cancelled while the job ran is left alone, and nothing is released or cancelled for it.
            if (! $this->whileOpen($proof->id, static fn () => null)) {
                return false;
            }
            $this->whileOpen($proof->id, fn (Operation $current) => $this->store->change($current, ['current_step' => self::WaitStep, 'state' => [...($current->state ?? []), 'capacity_wait_reason' => $reason]]));
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
     * The full input of one run: the claim as admitted, the cases of one command on its slot, and the release of that slot.
     * Captured in the admission transaction of the provision, so that no step of the run calls the Gateway.
     *
     * @param  array{command:string,file:string}  $case
     * @param  array<string,mixed>  $input  the provision input
     * @return array<string,mixed>
     */
    private function runInput(Operation $proof, string $commit, string $base, array $case, array $input, Task $group, Instance $instance): array
    {
        $instance->loadMissing('node');
        $ssh = app(SwampSshExecutionInput::class)->forNode($instance->node);

        return [
            'proof_operation_id' => $proof->id, 'commit' => $commit, 'command' => $case['command'], 'file' => $case['file'],
            'caller_node_id' => $proof->input['caller_node_id'] ?? null, 'ci_pool' => ($proof->input['ci_pool'] ?? false) === true,
            'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $input['node_id'],
            'execution_secrets' => $input['execution_secrets'] ?? [],
            'provision' => array_diff_key($input, ['execution_secrets' => true]),
            'verify' => ['group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'command' => $case['command'],
                'file' => $case['file'], 'ssh_execution' => $ssh, 'checkout_path' => $instance->checkout_path,
                // The proof of a new case switches the checkout to the merge base, with the new cases file of the head.
                'head' => $commit, 'merge_base' => $base],
            'cancel' => app(TaskCancelIntent::class)->captureForProof($group),
            ...(ValidatedData::object($proof->input)['keep_failed'] ?? false) === true ? ['keep_failed' => true] : [],
        ];
    }

    private function group(string $commit, string $command): Task
    {
        return app(CreateTaskGroupAction::class)->execute(new CreateTaskGroupData($this->settings->projectId(), 'Proof '.$command.' '.substr($commit, 0, 12),
            'Prove '.$command.' at commit '.$commit.'.', TaskGroupStatus::Todo, [new TaskInputData('Run the proof cases',
                'The Swamp workflow operation-proof-run proves the command in its own task VM.', [['id' => 'verdict', 'type' => 'review', 'description' => 'Every selected case passes.']])]));
    }

    private function select(string $commit, bool $all): ProofSelectionResult
    {
        try {
            $changes = $this->source->changes($commit);
            $list = $this->source->file($commit, ProofSelectionList::Path);
            $casesFiles = ProofSelection::casesFiles($this->source->proofPaths($commit));
        } catch (GitHubApiException) {
            throw new ResourceOperationException('proof.github_unavailable', 'GitHub could not be read, so the cases cannot be selected.', 503);
        }

        return ProofSelection::select($list, $changes, $all, $casesFiles);
    }
}
