<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Actions\Operations\StartOperationAction;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\PackageCacheSnapshot;
use App\Models\Project;
use App\Models\TaskVm;
use Illuminate\Support\Facades\DB;

final readonly class StartTaskVmPackageCacheMountAction
{
    public function __construct(private StartOperationAction $operations, private TaskVmSettings $settings) {}

    public function execute(TaskVm $vm, Project $project): ?Operation
    {
        if ($vm->provider !== TaskVmProviderKind::Incus) {
            return null;
        }

        return DB::transaction(function () use ($vm, $project): Operation {
            $vm = TaskVm::query()->lockForUpdate()->findOrFail($vm->id);
            if ($vm->state !== TaskVmState::Ready || $vm->group?->project_id !== $project->id || $vm->host_node_id === null) {
                throw new TaskVmException('task_vm.package_cache_mount_invalid', 'Cache preparation requires this Project’s ready task VM.');
            }
            $host = $this->settings->host($vm->host_node_id);
            $artifacts = PackageCacheSnapshot::query()->find($project->id)->artifacts ?? [];
            $digest = hash('sha256', json_encode($artifacts, JSON_THROW_ON_ERROR));
            $existing = Operation::query()->where('kind', 'task_vm.prepare_package_cache')->where('target_type', 'task_vm')->where('target_id', $vm->id)
                ->where('input->project_id', $project->id)->where('input->snapshot_digest', $digest)->whereNotIn('status', ['failed', 'cancelled'])->first();
            if ($existing !== null) {
                return $existing;
            }

            return $this->operations->execute('task_vm.prepare_package_cache', 'task_vm', $vm->id, $vm->host_node_id,
                ['task_vm_id' => $vm->id, 'project_id' => $project->id, 'host_node_id' => $vm->host_node_id, 'guest' => ['project' => $host->project, 'name' => $vm->name],
                    'artifacts_json' => json_encode(['artifacts' => $artifacts], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), 'snapshot_digest' => $digest,
                    'deadline_at' => now()->addMinutes(10)->getTimestamp()], 'task-vm', null);
        });
    }
}
