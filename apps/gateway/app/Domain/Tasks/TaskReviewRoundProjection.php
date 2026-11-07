<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\DirectOperationTimeout;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Models\TaskCheck;
use App\Models\TaskReviewRound;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal event of one `operation-task-review-round` run once: the task VM as ready once its resume step succeeded,
 * the metrics of the reviewer and author threads, the verdict and the pushed commit on the round, and the check row of the fix.
 * Every end records the round, so the gate check never waits on a round that ended. See docs/cli/task/review-round.mmd.
 */
final readonly class TaskReviewRoundProjection implements DirectOperationProjection, DirectOperationStartRefusal, DirectOperationTimeout, DirectOperationWorkflowInput
{
    /** The workflow steps whose failure has a code of its own; any other failed step of a turn is `tasks.turn_failed`. */
    private const array StepCodes = [
        'Resume the VM' => 'task_vm.resume_failed',
        'Check the fix turn' => 'tasks.review_fix_not_ready',
        'Check the fix' => 'tasks.review_fix_not_passed', 'Settle the check' => 'tasks.review_fix_not_passed', 'Check the fix passed' => 'tasks.review_fix_not_passed',
        'Clean up the token' => 'tasks.review_round_failed', 'Confirm the round' => 'tasks.review_round_failed',
    ];

    /**
     * The admitted input with vault key names only. A public read of the Operation calls this, so it has no effect.
     *
     * @return array<string,mixed>
     */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);
        $intent = array_diff_key($input, ['node_id' => true, 'execution_secrets' => true, 'resume_secrets' => true, 'publish_secrets' => true]);
        if (isset($intent['check'])) {
            $intent['check'] = TaskCheckProjection::workflowInput(ValidatedData::object($intent['check']))['intent'];
        }

        return [
            'intent' => $intent,
            'execution_secrets' => $input['execution_secrets'],
            // An empty map must reach the workflow as an object, not as an empty JSON list.
            'resume_secrets' => (object) ValidatedData::object($input['resume_secrets']),
            'publish_secrets' => $input['publish_secrets'],
        ];
    }

    /**
     * The run starts now, so the Pi token is written to the vault now, once, and not when the call was admitted or when someone reads the Operation.
     *
     * @return array<string,mixed>
     */
    public function workflowInput(Operation $operation): array
    {
        $input = $this->input($operation);
        $secrets = ValidatedData::object($input['execution_secrets']);
        $pi = ValidatedData::object($secrets['pi_token']);
        $vm = TaskVm::query()->find(ValidatedData::integer(ValidatedData::object(ValidatedData::object($operation->input)['resume'])['task_vm_id']));
        if (! $vm instanceof TaskVm || ! is_string($vm->pi_token) || strlen($vm->pi_token) < 32) {
            throw new ResourceOperationException('tasks.review_round_refused', 'The task VM or its Pi credential is gone, so the round cannot start.', 409);
        }

        return [...$input, 'execution_secrets' => [...$secrets, 'pi_token' => app(SwampCommandStep::class)->executionCopy(ValidatedData::string($pi['vault']), ValidatedData::string($pi['key']), $vm->pi_token)]];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $native = ValidatedData::object($data['result'] ?? []);
            $status = ValidatedData::string($data['status']);
            $failed = in_array($status, ['failed', 'interrupted'], true);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($operation->result ?? []));
            }
            $intent = ValidatedData::object($operation->input);
            $step = $failed ? TaskPrepResults::failedStep($native, $data) : null;
            $code = $failed ? (self::StepCodes[$step ?? ''] ?? ($step === 'Push the fix' ? TaskPrepResults::publishCode($data) : 'tasks.turn_failed')) : null;

            // The resume step is a child run, not a task_vm.resume Operation, so the VM is recorded as ready here once the step succeeded.
            if (NativeRunResult::stepStatus($native, 'Resume the VM') === 'succeeded') {
                $vm = TaskVm::query()->lockForUpdate()->find(ValidatedData::integer(ValidatedData::object($intent['resume'])['task_vm_id']));
                if ($vm instanceof TaskVm && $vm->state === TaskVmState::Parked) {
                    $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null]);
                }
            }
            $review = TaskPrepResults::turn($native['review'] ?? null);
            if ($review !== null) {
                TaskTurnProjection::recordThread($operation->target_id, ValidatedData::integer(ValidatedData::object($intent['review'])['thread_id']), $review);
            }
            $fix = TaskPrepResults::turn($native['fix'] ?? null);
            if ($fix !== null) {
                TaskTurnProjection::recordThread($operation->target_id, ValidatedData::integer(ValidatedData::object($intent['fix'])['thread_id']), $fix);
            }
            $round = TaskReviewRound::query()->where('operation_id', $operation->id)->firstOrFail();
            $verdict = $this->verdict($native, $status === 'succeeded');
            $pushed = NativeRunResult::stepStatus($native, 'Push the fix') === 'succeeded' && is_string($native['commit'] ?? null) && $native['commit'] !== '' ? $native['commit'] : null;
            $receipt = is_array($review['receipt'] ?? null) ? $review['receipt'] : [];
            $round->update(['verdict' => $verdict, 'pushed_commit' => $pushed, 'summary' => is_string($receipt['summary'] ?? null) ? mb_substr($receipt['summary'], 0, 8000) : null]);
            $this->closeCheck($round, $native, $failed);

            $result = $failed
                ? ['error_code' => $code, 'failed_step' => $step, 'verdict' => $verdict->value]
                : ['round' => $round->round, 'verdict' => $verdict->value, 'commit' => $pushed];
            $data['result'] = $result;
            $data['error_code'] = $code;

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }

    /** The budget ran out before the terminal event: the round ends as failed, so the gate check falls back instead of waiting for it. */
    public function timedOut(Operation $operation): void
    {
        $this->abandon($operation);
    }

    /** Swamp refused the start, so no run began: the round ends as failed. */
    public function refused(Operation $operation): void
    {
        $this->abandon($operation);
    }

    private function abandon(Operation $operation): void
    {
        $round = TaskReviewRound::query()->where('operation_id', $operation->id)->whereNull('verdict')->first();
        if ($round instanceof TaskReviewRound) {
            $round->update(['verdict' => TaskReviewRoundVerdict::Failed]);
            $this->closeCheck($round, [], true);
        }
    }

    /**
     * What the reviewer found. A run that did not succeed keeps findings, because the fix may have failed after the review ended, but
     * never a pass: the merge step must not follow a run that ended in error.
     *
     * @param  array<string,mixed>  $native
     */
    private function verdict(array $native, bool $succeeded): TaskReviewRoundVerdict
    {
        $verdict = is_string($native['verdict'] ?? null) ? TaskReviewRoundVerdict::tryFrom($native['verdict']) : null;

        return match (true) {
            $verdict === TaskReviewRoundVerdict::Findings => $verdict,
            $verdict === TaskReviewRoundVerdict::Pass && $succeeded => $verdict,
            default => TaskReviewRoundVerdict::Failed,
        };
    }

    /**
     * The check row of the fix gets what the local check found. A round that made no check (a pass, or a run that ended before the fix)
     * closes the row as cancelled, and a check that began but left no result closes it as failed.
     *
     * @param  array<string,mixed>  $native
     */
    private function closeCheck(TaskReviewRound $round, array $native, bool $failed): void
    {
        $check = $round->check_id === null ? null : TaskCheck::query()->find($round->check_id);
        if (! $check instanceof TaskCheck) {
            return;
        }
        $json = is_string($native['checked'] ?? null) ? json_decode($native['checked'], true) : null;
        if (is_array($json)) {
            $result = ValidatedData::object($json);
            TaskCheckProjection::record($check, $result['outcome'] ?? 'failed', $result);

            return;
        }
        $ran = in_array(NativeRunResult::stepStatus($native, 'Check the fix'), ['succeeded', 'failed'], true);
        TaskCheckProjection::record($check, $failed && $ran ? 'failed' : 'cancelled', []);
    }
}
