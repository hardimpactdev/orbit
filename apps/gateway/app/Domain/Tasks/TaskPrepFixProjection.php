<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\StartTaskParkAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal event of one `operation-task-prep-fix` run once: the task VM as ready once its resume step succeeded,
 * the metrics of each thread, and the result of the corrections. A run that pushed them parks the group again. See
 * docs/reference/tasks.md#apply-the-corrections.
 */
final readonly class TaskPrepFixProjection implements DirectOperationProjection
{
    /** The workflow steps whose failure has a code of its own; any other failed step of a turn is `tasks.turn_failed`. */
    private const array StepCodes = [
        'Resume the VM' => 'task_vm.resume_failed',
        'Check the corrections' => 'tasks.prep_not_committed', 'Settle fix 1' => 'tasks.prep_not_committed',
        'Plan the cases 0' => 'tasks.prep_plan_failed', 'Plan the cases 1' => 'tasks.prep_plan_failed',
        'Review has no findings' => 'tasks.prep_review_unresolved',
    ];

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);

        return [
            'intent' => ['resume' => $input['resume'], 'author' => $input['author'], 'fix' => $input['fix'], 'reviews' => $input['reviews'], 'publish' => $input['publish'], 'plan' => $input['plan']],
            'execution_secrets' => $input['execution_secrets'],
            'resume_secrets' => $input['resume_secrets'],
            'publish_secrets' => $input['publish_secrets'],
        ];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        $projected = DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $native = ValidatedData::object($data['result'] ?? []);
            $status = ValidatedData::string($data['status']);
            $failed = in_array($status, ['failed', 'interrupted'], true);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($operation->result ?? []));
            }
            $intent = ValidatedData::object($operation->input);
            $step = $failed ? $this->failedStep($native, $data) : null;
            $code = $failed ? (self::StepCodes[$step ?? ''] ?? ($step === 'Push the corrections' ? self::publishCode($data) : 'tasks.turn_failed')) : null;

            // The resume step is a child run, not a task_vm.resume Operation, so the VM is recorded as ready here once the step succeeded.
            if (NativeRunResult::stepStatus($native, 'Resume the VM') === 'succeeded') {
                $vm = TaskVm::query()->lockForUpdate()->find(ValidatedData::integer(ValidatedData::object($intent['resume'])['task_vm_id']));
                if ($vm instanceof TaskVm && $vm->state === TaskVmState::Parked) {
                    $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null, ...TaskVm::ForgetTopology]);
                }
            }
            $author = ValidatedData::integer(ValidatedData::object($intent['author'])['thread_id']);
            $reviews = array_map(static fn (mixed $turn): int => ValidatedData::integer(ValidatedData::object($turn)['thread_id']), array_values(is_array($intent['reviews']) ? $intent['reviews'] : []));
            $rounds = 0;
            foreach (['author' => $author, 'review_1' => $reviews[0], 'fix_1' => $author, 'review_2' => $reviews[1]] as $name => $thread) {
                $result = TaskPrepResults::turn($native[$name] ?? null);
                if ($result !== null) {
                    TaskTurnProjection::recordThread($operation->target_id, $thread, $result);
                    $rounds += str_starts_with($name, 'review') ? 1 : 0;
                }
            }
            $result = ['error_code' => $code, 'failed_step' => $step];
            if ($status === 'succeeded') {
                $result = ['branch' => ValidatedData::string(ValidatedData::object($intent['publish'])['branch']), 'commit' => is_string($native['head'] ?? null) ? $native['head'] : null, 'rounds' => $rounds];
            } elseif ($step === 'Review has no findings' && is_string($native['findings'] ?? null)) {
                $result['findings'] = mb_strcut($native['findings'], 0, 4000, 'UTF-8');
            }
            $data['result'] = $result;
            $data['error_code'] = $code;

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
        if ($projected->status->value === 'succeeded') {
            $this->parkAgain($projected);
        }

        return $projected;
    }

    /** The corrections are on the pull request, so the group stops its VM again while the spec waits for the owner. */
    private function parkAgain(Operation $operation): void
    {
        $group = Task::topLevel()->find($operation->target_id);
        if (! $group instanceof Task) {
            return;
        }
        try {
            app(StartTaskParkAction::class)->execute($group, 'prep-fix', null);
        } catch (ResourceOperationException $exception) {
            report($exception);
        }
    }

    /**
     * @param  array<string,mixed>  $native
     * @param  array<string,mixed>  $data
     */
    private function failedStep(array $native, array $data): ?string
    {
        $failure = ValidatedData::object($data['failure'] ?? []);
        $steps = ValidatedData::object($native['summary'] ?? [])['failed_steps'] ?? [];
        $step = $failure['failed_step'] ?? (is_array($steps) ? ($steps[0] ?? null) : null);

        return is_string($step) ? $step : null;
    }

    /** @param array<string,mixed> $data */
    private static function publishCode(array $data): string
    {
        $failure = is_array($data['failure'] ?? null) ? $data['failure'] : [];
        $detail = $failure['detail'] ?? null;

        return in_array($detail, ['tasks.head_moved', 'tasks.not_fast_forward', 'tasks.workflows_permission', 'tasks.push_failed', 'tasks.publish_failed', 'tasks.repository_execution_unknown'], true)
            ? $detail : 'tasks.publish_failed';
    }
}
