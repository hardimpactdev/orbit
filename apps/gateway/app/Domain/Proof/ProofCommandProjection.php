<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupEnding;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskProvisionProjection;
use App\Domain\TaskVms\TaskVmProviderWorkflowInput;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Jobs\Proof\CancelProofGroup;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Hands the admitted run of one command to its Swamp parent `operation-proof-run`, and records the run once, from its terminal
 * event: the VM and workspace as provision records them, the verdict of each case, and, when the run destroyed its VM, the end
 * of the task group. No step calls the Gateway. See docs/cli/proof/run.mmd.
 */
final readonly class ProofCommandProjection implements DirectOperationProjection, DirectOperationStartRefusal, DirectOperationTimeout
{
    /** The code of a run whose VM or fleet never got to run its cases. */
    public const string RunFailed = 'proof.run_failed';

    /** The code of a run whose VM workspace was not at the commit under proof when the check ran, before any case. */
    public const string WorkspaceCommitMismatch = 'proof.workspace_commit_mismatch';

    /** How long a failed run keeps its VM when `proof:run` is given `--keep-failed`. */
    public const int KeepMinutes = 60;

    public function __construct(private TaskProvisionProjection $provision, private TaskGroupEnding $ending, private ProofRunSettlement $settlement) {}

    /** The run's inputs: the provision and the destruction as the workflows `operation-task-provision` and `operation-task-cancel` take them. */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);
        $provision = $this->provision->input(self::provisionView($operation));
        $cancel = TaskVmProviderWorkflowInput::envelope(ValidatedData::object($input['cancel']));

        $verifications = [];
        $assignments = ProofBatchPlan::stored($input['assignments'] ?? [['command' => $input['command'], 'file' => $input['file'], 'batch' => '']]);
        foreach ($assignments as $index => $assignment) {
            $verifications[] = [...ValidatedData::object($input['verify']), 'command' => $assignment['command'], 'file' => $assignment['file'],
                'batch' => $assignment['batch'], ...($assignment['cases'] === [] ? [] : ['case_ids' => $assignment['cases']]), 'step' => 'Prove assigned batches-'.$index, 'restore_first' => true];
        }

        return [
            // The cancel's secrets go nested in the intent, past the submission that turns an empty top-level map into an object.
            'intent' => ['provision' => $provision['intent'], 'verify' => $input['verify'], 'verifications' => $verifications, 'cancel' => ['node_id' => ValidatedData::integer($cancel['intent']['node_id']),
                'intent' => $cancel['intent'], 'execution_secrets' => $cancel['execution_secrets'] === [] ? (object) [] : $cancel['execution_secrets']]],
            'execution_secrets' => $provision['execution_secrets'],
            ...self::keepFlag($input),
        ];
    }

    /**
     * The input of the workflow that tells its destroy step to stay out of the way of a failed run (`proof:run --keep-failed`). It is
     * present only when the proof was started with the flag; the workflow declares it as an optional boolean.
     *
     * @param  array<string,mixed>  $input  the input of the run
     * @return array{keep_failed?: true}
     */
    public static function keepFlag(array $input): array
    {
        return ($input['keep_failed'] ?? false) === true ? ['keep_failed' => true] : [];
    }

    /**
     * A run the Gateway timed out keeps the verdicts of the batches that ended before it ({@see ProofBatchProjection::applyChild()});
     * the cases of the others are unrun. It may leave its VM; the group is cancelled the normal way.
     */
    public function timedOut(Operation $operation): void
    {
        $input = ValidatedData::object($operation->input);
        $lines = [];
        foreach (ValidatedData::object($operation->state[ProofBatchProjection::BatchReports] ?? []) as $key => $report) {
            foreach (explode("\n", trim(ValidatedData::string($report))) as $line) {
                if (trim($line) !== '') {
                    $lines[] = $key."\t".trim($line);
                }
            }
        }
        $verdicts = ProofShardVerdicts::rows(ProofBatchPlan::stored($input['assignments'] ?? [['command' => $input['command'], 'file' => $input['file'], 'batch' => '']]), implode("\n", $lines));
        app(OperationStore::class)->change($operation, ['result' => [...($operation->result ?? []), 'commit' => $input['commit'], 'command' => $input['command'],
            'file' => $input['file'], 'outcome' => 'failed', 'cases' => $verdicts['cases']]]);
        DB::afterCommit(static fn () => CancelProofGroup::dispatch($operation->id));
        $this->settle($operation);
    }

    /** A definite start refusal has no Node effects, but its claimed identity still needs releasing. */
    public function refused(Operation $operation): void
    {
        $this->provision->refused(self::provisionView($operation));
        DB::afterCommit(fn () => $this->settle($operation));
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $projected = DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($operation->status->terminal()) {
                // A repeat of the terminal event finds the run recorded: it answers with what was recorded.
                return app(DirectOperation::class)->project($operation, [...$data, 'status' => $operation->status->value, 'error_code' => $operation->error_code], ValidatedData::object($operation->result ?? []));
            }
            $native = ValidatedData::object($data['result'] ?? []);
            $input = ValidatedData::object($operation->input);
            $status = ValidatedData::string($data['status']);

            // The slot and the workspace of the group, as a claim records them. The run releases the slot itself, always.
            $claimed = is_array($native['provision'] ?? null);
            if ($status === 'succeeded' && ! $claimed) {
                throw new ResourceOperationException('operation.invalid_result', 'The proof run reports no claimed slot.', 422);
            }
            $this->provision->record(self::provisionView($operation), $claimed ? 'succeeded' : ($status === 'cancelled' ? 'cancelled' : 'failed'),
                $claimed ? null : 'tasks.provision_failed', static fn (): Operation => $operation, release: false);

            $report = is_string($native['report'] ?? null) ? $native['report'] : '';
            $verdicts = ProofShardVerdicts::rows(ProofBatchPlan::stored($input['assignments'] ?? [['command' => $input['command'], 'file' => $input['file'], 'batch' => '']]), $report);
            if (in_array($status, ['succeeded', 'failed'], true)) {
                $passed = $status === 'succeeded' && ProofVerdicts::passed($verdicts['cases']);
                $data['status'] = $passed ? 'succeeded' : 'failed';
                $data['error_code'] = $passed ? null : ($verdicts['ran'] ? 'proof.cases_failed' : self::RunFailed);
            }
            // The workspace of the VM was not at the commit under proof, so no case ran: the code and both commits say what to fix.
            $workspace = $status === 'failed' ? self::workspace($input, $native) : null;
            if ($workspace !== null) {
                $data['error_code'] = self::WorkspaceCommitMismatch;
            }

            // The run's own destroy step ends the group. A failed run that kept its VM (`keep_failed`) leaves the group open for 60 minutes; any other end leaves the VM to the normal cancel.
            $kept = null;
            if (NativeRunResult::stepStatus($native, 'Release the slot') === 'succeeded') {
                $group = Task::topLevel()->lockForUpdate()->find(ValidatedData::integer($input['group_id']));
                if ($group instanceof Task && $group->status !== TaskGroupStatus::Cancelled) {
                    $this->ending->settle($group, ValidatedData::object($input['cancel']), 'tasks.cancel');
                }
            } elseif (($kept = $this->kept($operation, $input, $native, $data)) === null) {
                DB::afterCommit(static fn () => CancelProofGroup::dispatch($operation->id));
            }

            return app(DirectOperation::class)->project($operation, $data, ['commit' => $input['commit'], 'command' => $input['command'], 'file' => $input['file'],
                'outcome' => ($data['status'] ?? null) === 'succeeded' ? 'passed' : 'failed', 'cases' => $verdicts['cases'], ...($workspace === null ? [] : ['workspace' => $workspace]),
                ...($kept === null ? [] : ['kept' => $kept])]);
        });
        // After the commit, so that two runs which end together each see the other, and the last one closes the proof.
        DB::afterCommit(fn () => $this->settle($projected));

        return $projected;
    }

    /**
     * The record of the VM that a failed run kept, or null when the run kept none: the flag was off, the workflow destroyed (or never
     * started to destroy) the VM, the run did not fail, or its group has no VM. A run that failed in its setup keeps the VM it has, in whatever
     * state. The keep ends at the time recorded here (ProofRunWatch).
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $native
     * @param  array<string,mixed>  $data
     * @return array{vm:string,group_id:int,keep_until:string,cancel_requested_at:null}|null
     */
    private function kept(Operation $run, array $input, array $native, array $data): ?array
    {
        if (($input['keep_failed'] ?? false) !== true || ($data['status'] ?? null) !== 'failed'
            || NativeRunResult::stepStatus($native, 'Release the slot') !== 'skipped') {
            return null;
        }
        $groupId = ValidatedData::integer($input['group_id']);
        $vm = TaskVm::query()->where('group_id', $groupId)->where('state', '!=', TaskVmState::Destroyed)->orderByDesc('id')->first();
        if (! $vm instanceof TaskVm) {
            return null;
        }

        return ['vm' => $vm->name, 'group_id' => $groupId, 'keep_until' => now()->addMinutes(self::KeepMinutes)->utc()->format(ProofRunWatch::Time), 'cancel_requested_at' => null];
    }

    /**
     * The commits of a run whose check of the workspace failed, or null when it did not fail. `found` is the commit the VM printed, and an
     * empty string when it printed none that looks like one, so a message never carries text from the VM.
     *
     * @param  array<string,mixed>  $input  the input of the run
     * @param  array<string,mixed>  $native  the native result of the run
     * @return array{expected:string,found:string}|null
     */
    private static function workspace(array $input, array $native): ?array
    {
        if (NativeRunResult::stepStatus($native, 'Check the workspace commit') !== 'failed') {
            return null;
        }
        $steps = ValidatedData::object($native['steps'] ?? []);
        $read = is_array($steps['Read the workspace commit'] ?? null) ? $steps['Read the workspace commit'] : [];
        $found = is_string($read['stdout'] ?? null) ? trim($read['stdout']) : '';

        return ['expected' => ValidatedData::string($input['commit']), 'found' => preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $found) === 1 ? $found : ''];
    }

    private function settle(Operation $run): void
    {
        $proof = $run->input['proof_operation_id'] ?? null;
        if (is_int($proof)) {
            $this->settlement->settle($proof);
        }
    }

    /**
     * The provision of one run as the Operation of its own kind: the provision intent as input, so the provision records apply to it unchanged.
     */
    private static function provisionView(Operation $operation): Operation
    {
        $view = $operation->replicate();
        $view->id = $operation->id;
        $view->kind = 'tasks.provision';
        $input = ValidatedData::object($operation->input);
        $view->input = [...ValidatedData::object($input['provision']), 'execution_secrets' => $input['execution_secrets']];

        return $view;
    }
}
