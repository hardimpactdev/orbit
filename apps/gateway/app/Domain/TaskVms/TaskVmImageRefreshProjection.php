<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Console\Scheduling\GatewaySchedule;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\DirectOperationStartRefusal;
use App\Infrastructure\Swamp\NativeRunResult;
use App\Jobs\TaskVms\RefreshTaskVmImages;
use App\Models\Operation;
use App\Models\TaskVm;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Projects the terminal event of one `operation-task-vm-image-refresh` run once (docs/cli/task/vm-image-refresh.mmd). When the run
 * passed, the Gateway promotes its snapshot of the host's base VM: it becomes the host's latest build, and task starts copy it. A failed run
 * records nothing, so the host keeps the snapshot it had.
 */
final readonly class TaskVmImageRefreshProjection implements DirectOperationProjection, DirectOperationStartRefusal
{
    /** The last step: the old snapshots go once the new one is proven. */
    public const string EndCheck = 'Prune old snapshots';

    /** @return array<string,mixed> */
    public function input(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);

        return ['intent' => ValidatedData::object($input['intent']), 'execution_secrets' => ValidatedData::object($input['execution_secrets'])];
    }

    /** @param array<string,mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        return DB::transaction(function () use ($operation, $data): Operation {
            $operation = Operation::query()->lockForUpdate()->findOrFail($operation->id);
            $native = ValidatedData::object($data['result'] ?? []);
            $status = ValidatedData::string($data['status']);
            if ($operation->status->terminal()) {
                return app(DirectOperation::class)->project($operation, $data, ValidatedData::object($operation->result ?? []));
            }
            $code = in_array($status, ['failed', 'interrupted'], true) ? 'task_vm.image_refresh_failed' : null;
            $result = ['error_code' => $code];
            $build = $status === 'succeeded' ? $this->record($operation) : null;
            if ($build !== null) {
                $result = $build;
            }
            // The identity that proved the candidate is free once the proof's release passed. Otherwise it is failed, and the slot rule
            // releases it again before anything else takes it.
            $slot = TaskVm::query()->lockForUpdate()->find(ValidatedData::integer(ValidatedData::object($operation->input)['task_vm_id']));
            if ($slot instanceof TaskVm) {
                $released = NativeRunResult::stepStatus($native, 'Prove the candidate') === 'succeeded';
                app(TaskVmSlotReleaseProjection::class)->record($slot, $released, $released ? null : 'task_vm.image_refresh_failed');
            }
            $data['result'] = $result;
            $data['error_code'] = $code;
            // A release came while this run was open: its commit waits for the lock this end frees, and the newest one is the one asked for.
            $next = ($operation->state ?? [])[TaskVmImageRefreshIntent::Next] ?? null;
            if (is_string($next)) {
                $host = $operation->target_id;
                DB::afterCommit(static function () use ($host, $next): void {
                    try {
                        GatewaySchedule::dispatch(new RefreshTaskVmImages($host, $next));
                    } catch (Throwable $failure) {
                        // The daily trigger asks again; the end of a run is never held by it.
                        Log::warning('The queued task image refresh was not dispatched.', ['node_id' => $host, 'message' => $failure->getMessage()]);
                    }
                });
            }

            return app(DirectOperation::class)->project($operation, $data, $result);
        });
    }

    /** A start the Gateway refused never ran: the slot that was to prove the candidate is failed, so it stops counting against the host's capacity. */
    public function refused(Operation $operation): void
    {
        $slot = TaskVm::query()->lockForUpdate()->find(ValidatedData::integer(ValidatedData::object($operation->input)['task_vm_id']));
        if ($slot instanceof TaskVm) {
            app(TaskVmSlotReleaseProjection::class)->record($slot, false, $operation->error_code ?? 'task_vm.image_refresh_failed');
        }
    }

    /**
     * The promoted snapshot as the host's latest build. Its recipe identity is the one of the release the run converged to.
     *
     * @return array<string,mixed>
     */
    private function record(Operation $operation): array
    {
        $input = ValidatedData::object($operation->input);
        $host = ValidatedData::object(ValidatedData::object($input['intent'])['host']);
        $base = ValidatedData::object($host['base']);
        $snapshot = ValidatedData::string($host['snapshot']);
        $build = new TaskVmImageBuild;
        $build->forceFill(['host_node_id' => ValidatedData::integer($host['node_id']), 'recipe_identity' => ValidatedData::string($input['recipe_identity']),
            'images' => ['base' => ['project' => ValidatedData::string($host['project']), 'type' => 'snapshot', 'vm' => ValidatedData::string($base['vm']),
                'seed' => ValidatedData::string($base['seed']), 'snapshot' => $snapshot]],
            'state' => TaskVmImageBuildState::Succeeded, 'promoted_at' => now(), 'steps' => []])->save();

        return ['refreshed' => true, 'host_node_id' => $build->host_node_id, 'snapshot' => $snapshot, 'previous_snapshot' => $host['current'] ?? null,
            'build_id' => $build->id];
    }
}
