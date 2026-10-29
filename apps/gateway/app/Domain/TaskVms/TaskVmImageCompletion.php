<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Fleet\RecipeMaintenanceAlerts;
use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Models\TaskVmImageBuild;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;

final readonly class TaskVmImageCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        $build = TaskVmImageBuild::query()->findOrFail(ValidatedData::integer($operation->input['build_id']));
        $changed = TaskVmImageBuild::query()->whereKey($build->id)->where('attempt', $operation->input['attempt'])
            ->where('state', TaskVmImageBuildState::Running)->whereNull('cleanup_started_at')
            ->update(['state' => $outcome->status === OperationStatus::Succeeded ? TaskVmImageBuildState::Succeeded : TaskVmImageBuildState::Failed]);
        if ($changed === 1 && $outcome->status !== OperationStatus::Succeeded) {
            $steps = $build->steps;
            foreach ($steps as &$entry) {
                if ($entry['step'] === $build->current_step?->value && $entry['program'] === $build->current_program) {
                    $entry['error'] = $outcome->errorCode ?? 'operation.cancelled';
                }
            }
            unset($entry);
            $build->update(['steps' => $steps]);
            DB::afterCommit(static fn () => app(RecipeMaintenanceAlerts::class)->halted(
                "Image build [{$build->id}] failed on host [{$build->host_node_id}]. Inspect the Operation and retained builder, then resume or retry.",
                $build->id, incident: RecipeMaintenanceAlerts::imageIncident($build)));
        }
    }
}
