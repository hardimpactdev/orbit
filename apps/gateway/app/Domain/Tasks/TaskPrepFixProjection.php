<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Domain\Operations\DirectOperationWorkflowInput;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

/**
 * Projects the terminal event of one `operation-task-prep-fix` run once: the task VM as ready once its resume step succeeded,
 * the metrics of each thread, and the result of the corrections. A run that pushed them releases its VM. See
 * docs/reference/tasks.md#apply-the-corrections.
 */
final readonly class TaskPrepFixProjection implements DirectOperationProjection, DirectOperationStartRefusal, DirectOperationWorkflowInput
{
    public function refused(Operation $operation): void
    {
        TaskVmReentry::refused($operation);
    }

    /** The workflow steps whose failure has a code of its own; any other failed step of a turn is `tasks.turn_failed`. */
    private const array StepCodes = [
        'Resume the VM' => 'task_vm.resume_failed',
        'Check the corrections' => 'tasks.prep_not_committed', 'Settle fix 1' => 'tasks.prep_not_committed',
        'Plan the cases 0' => 'tasks.prep_plan_failed', 'Plan the cases 1' => 'tasks.prep_plan_failed',
        'Review has no findings' => 'tasks.prep_review_unresolved',
    ];

    /**
     * The admitted input with vault key names only. A public read of the Operation calls this, so it has no effect.
     *
     * @return array<string,mixed>
     */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);

        return [
            'intent' => ['task_vm_id' => $input['task_vm_id'], ...(isset($input['provision']) ? ['provision' => $input['provision']] : []), ...(isset($input['resume']) ? ['resume' => $input['resume']] : []), 'author' => $input['author'], 'fix' => $input['fix'], 'reviews' => $input['reviews'], 'publish' => $input['publish'], 'plan' => $input['plan']],
            'execution_secrets' => $input['execution_secrets'],
            // An empty map must reach the workflow as an object, not as an empty JSON list.
            'resume_secrets' => (object) ValidatedData::object($input['resume_secrets'] ?? []),
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
        $pi = ValidatedData::object(ValidatedData::object($input['execution_secrets'])['pi_token']);
        $vm = TaskVm::query()->find(ValidatedData::integer(ValidatedData::object($operation->input)['task_vm_id']));
        if (! $vm instanceof TaskVm || ! is_string($vm->pi_token) || strlen($vm->pi_token) < 32) {
            throw new ResourceOperationException('tasks.prep_fix_refused', 'The task VM or its Pi credential is gone, so the corrections cannot start.', 409);
        }

        return [...$input, 'execution_secrets' => ['pi_token' => app(SwampCommandStep::class)->executionCopy(ValidatedData::string($pi['vault']), ValidatedData::string($pi['key']), $vm->pi_token)]];
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
            TaskVmReentry::record($operation, $native);
            $step = $failed ? TaskPrepResults::failedStep($native, $data) : null;
            $code = $failed ? (self::StepCodes[$step ?? ''] ?? ($step === 'Push the corrections' ? TaskPrepResults::publishCode($data) : 'tasks.turn_failed')) : null;

            // The resume step is a child run, not a task_vm.resume Operation, so the VM is recorded as ready here once the step succeeded.
            if (NativeRunResult::stepStatus($native, 'Resume the VM') === 'succeeded') {
                $vm = TaskVm::query()->lockForUpdate()->find(ValidatedData::integer($intent['task_vm_id']));
                if ($vm instanceof TaskVm && $vm->state === TaskVmState::Parked) {
                    $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null]);
                }
            }
            $author = ValidatedData::integer(ValidatedData::object($intent['author'])['thread_id']);
            $reviews = array_map(static fn (mixed $turn): int => ValidatedData::integer(ValidatedData::object($turn)['thread_id']), array_values(is_array($intent['reviews']) ? $intent['reviews'] : []));
            $rounds = 0;
            foreach (['author' => $author, 'review_1' => $reviews[0], 'fix_1' => $author, 'review_2' => $reviews[1]] as $name => $thread) {
                $result = TaskPrepResults::turn($native[$name] ?? null);
                if ($result !== null) {
                    TaskTurnProjection::recordTurn($operation, $thread, $result);
                    $rounds += str_starts_with($name, 'review') ? 1 : 0;
                }
            }
            $result = ['error_code' => $code, 'failed_step' => $step];
            if ($status === 'succeeded') {
                $result = ['branch' => ValidatedData::string(ValidatedData::object($intent['publish'])['branch']), 'commit' => is_string($native['head'] ?? null) ? $native['head'] : null, 'rounds' => $rounds,
                    // The last review approved; its notes, such as runner tests the owner defers, stay readable here.
                    'notes' => is_string($native['findings'] ?? null) ? mb_strcut($native['findings'], 0, 4000, 'UTF-8') : null];
            } elseif ($step === 'Review has no findings' && is_string($native['findings'] ?? null)) {
                $result['findings'] = mb_strcut($native['findings'], 0, 4000, 'UTF-8');
            }
            $data['result'] = $result;
            $data['error_code'] = $code;

            $projected = app(DirectOperation::class)->project($operation, $data, $result);
            TaskVmReentry::release($projected);

            return $projected;
        });
    }
}
