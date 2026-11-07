<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupEnding;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskPrepResults;
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
final readonly class ProofCommandProjection implements DirectOperationProjection, DirectOperationTimeout
{
    /** The code of a run whose VM or fleet never got to run its cases. */
    public const string RunFailed = 'proof.run_failed';

    /** How long a failed run keeps its VM when `proof:run` is given `--keep-failed`. */
    public const int KeepMinutes = 60;

    public function __construct(private TaskProvisionProjection $provision, private TaskGroupEnding $ending, private ProofRunSettlement $settlement) {}

    /** The run's inputs: the provision and the destruction as the workflows `operation-task-provision` and `operation-task-cancel` take them. */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);
        $provision = $this->provision->input(self::provisionView($operation));
        $cancel = ValidatedData::object($input['cancel']);

        // A proof needs the nested fleet, so its provision always has the converge intent that the workflow takes as an input of its own.
        $converge = $provision['converge'] ?? throw new ResourceOperationException('tasks.provision_refused', 'A proof requires a task VM with a nested topology.', 422);

        return [
            'converge' => $converge,
            'intent' => ['provision' => $provision['intent'], 'verify' => $input['verify'],
                'cancel' => ['node_id' => ValidatedData::integer($cancel['node_id']), ...TaskVmProviderWorkflowInput::envelope($cancel)]],
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

    /** A run the Gateway timed out may leave its VM; the group is cancelled the normal way. */
    public function timedOut(Operation $operation): void
    {
        DB::afterCommit(static fn () => CancelProofGroup::dispatch($operation->id));
        $this->settle($operation);
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

            // The VM and the workspace of the group, from the same receipt provision records. A run that never got a VM ready records the failure.
            $receipt = TaskPrepResults::receipt($native['provision'] ?? null);
            if ($status === 'succeeded' && $receipt === null) {
                throw new ResourceOperationException('operation.invalid_result', 'The proof run reports no ready VM.', 422);
            }
            $this->provision->record(self::provisionView($operation), ['status' => $receipt !== null ? 'succeeded' : ($status === 'cancelled' ? 'cancelled' : 'failed'),
                'error_code' => $receipt !== null ? null : 'tasks.provision_failed'], $receipt ?? ['failed_step' => 'provision'], static fn (): Operation => $operation);

            $report = $native['steps'] ?? null;
            $report = is_array($report) && is_array($report['Report'] ?? null) && is_string($report['Report']['stdout'] ?? null) ? $report['Report']['stdout'] : '';
            $verdicts = ProofVerdicts::rows(ValidatedData::string($input['command']), ValidatedData::string($input['file']), $report);
            if (in_array($status, ['succeeded', 'failed'], true)) {
                $passed = $status === 'succeeded' && ProofVerdicts::passed($verdicts['cases']);
                $data['status'] = $passed ? 'succeeded' : 'failed';
                $data['error_code'] = $passed ? null : ($verdicts['ran'] ? 'proof.cases_failed' : self::RunFailed);
            }

            // The run's own destroy step ends the group. A failed run that kept its VM (`keep_failed`) leaves the group open for 60 minutes; any other end leaves the VM to the normal cancel.
            $kept = null;
            if (NativeRunResult::stepStatus($native, 'Destroy the VM') === 'succeeded') {
                $group = Task::topLevel()->lockForUpdate()->find(ValidatedData::integer($input['group_id']));
                if ($group instanceof Task && $group->status !== TaskGroupStatus::Cancelled) {
                    $this->ending->settle($group, ValidatedData::object($input['cancel']), 'tasks.cancel');
                }
            } elseif (($kept = $this->kept($operation, $input, $native, $data)) === null) {
                DB::afterCommit(static fn () => CancelProofGroup::dispatch($operation->id));
            }

            return app(DirectOperation::class)->project($operation, $data, ['commit' => $input['commit'], 'command' => $input['command'], 'file' => $input['file'],
                'outcome' => ($data['status'] ?? null) === 'succeeded' ? 'passed' : 'failed', 'cases' => $verdicts['cases'], ...($kept === null ? [] : ['kept' => $kept])]);
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
            || NativeRunResult::stepStatus($native, 'Destroy the VM') !== 'skipped') {
            return null;
        }
        $groupId = ValidatedData::integer($input['group_id']);
        $vm = TaskVm::query()->where('group_id', $groupId)->where('state', '!=', TaskVmState::Destroyed)->orderByDesc('id')->first();
        if (! $vm instanceof TaskVm) {
            return null;
        }

        return ['vm' => $vm->name, 'group_id' => $groupId, 'keep_until' => now()->addMinutes(self::KeepMinutes)->utc()->format(ProofRunWatch::Time), 'cancel_requested_at' => null];
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
