<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
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
 * Projects the terminal event of one `operation-task-build` run once: the task VM as ready once its resume step succeeded, the metrics
 * of the implementer thread, and the branch with the approved head. The group stays `running` for its driver. See docs/cli/tasks/build.mmd.
 */
final readonly class TaskBuildProjection implements DirectOperationProjection, DirectOperationWorkflowInput
{
    /** The workflow steps whose failure has a code of its own; the first implementer turn, and any other failed step, is `tasks.turn_failed`. */
    private const array StepCodes = ['Resume the VM' => 'task_vm.resume_failed', 'Sync the refs' => 'tasks.sync_failed'];

    /**
     * The admitted input with vault key names only. A public read of the Operation calls this, so it has no effect.
     *
     * @return array<string,mixed>
     */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);

        return [
            'intent' => ['resume' => $input['resume'], 'sync' => $input['sync'], 'turn' => $input['turn']],
            'execution_secrets' => $input['execution_secrets'],
            // An empty map must reach the workflow as an object, not as an empty JSON list.
            'resume_secrets' => (object) ValidatedData::object($input['resume_secrets']),
        ];
    }

    /**
     * The run starts now, so the Pi token and the read token of the sync child are written to the vault now, once, and not when the call was admitted or when someone reads the Operation.
     *
     * @return array<string,mixed>
     */
    public function workflowInput(Operation $operation): array
    {
        $input = $this->input($operation);
        $pi = ValidatedData::object(ValidatedData::object($input['execution_secrets'])['pi_token']);
        $vm = TaskVm::query()->find(ValidatedData::integer(ValidatedData::object(ValidatedData::object($operation->input)['resume'])['task_vm_id']));
        if (! $vm instanceof TaskVm || ! is_string($vm->pi_token) || strlen($vm->pi_token) < 32) {
            throw new ResourceOperationException('tasks.build_refused', 'The task VM or its Pi credential is gone, so the build cannot start.', 409);
        }

        $group = Task::topLevel()->with('project')->find($operation->target_id);
        if (! $group instanceof Task) {
            throw new ResourceOperationException('tasks.build_refused', 'The task group is gone, so the build cannot start.', 409);
        }
        $piCopy = app(SwampCommandStep::class)->executionCopy(ValidatedData::string($pi['vault']), ValidatedData::string($pi['key']), $vm->pi_token);
        // The read token of the sync child: the Gateway writes it to the admitted key, so no step of the run asks for it.
        app(TaskGitHubTokens::class)->writeClone($group, ValidatedData::object(ValidatedData::object(ValidatedData::object($operation->input)['sync'])['git_authorization']));

        return [...$input, 'execution_secrets' => ['pi_token' => $piCopy]];
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
            $code = $failed ? (self::StepCodes[$step ?? ''] ?? 'tasks.turn_failed') : null;

            // The resume step is a child run, not a task_vm.resume Operation, so the VM is recorded as ready here once the step succeeded.
            if (NativeRunResult::stepStatus($native, 'Resume the VM') === 'succeeded') {
                $vm = TaskVm::query()->lockForUpdate()->find(ValidatedData::integer(ValidatedData::object($intent['resume'])['task_vm_id']));
                if ($vm instanceof TaskVm && $vm->state === TaskVmState::Parked) {
                    $vm->update(['state' => TaskVmState::Ready, 'ready_at' => now(), 'error_code' => null, 'error_message' => null]);
                }
            }
            $turn = TaskPrepResults::turn($native['turn'] ?? null);
            if ($turn !== null) {
                TaskTurnProjection::recordTurn($operation, ValidatedData::integer(ValidatedData::object($intent['turn'])['thread_id']), $turn);
            }
            $result = $status === 'succeeded'
                ? ['branch' => 'task-'.$operation->target_id, 'head' => ValidatedData::string(ValidatedData::object($intent['approval'])['head'])]
                : ['error_code' => $code, 'failed_step' => $step];
            $data['result'] = $result;
            $data['error_code'] = $code;

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }
}
