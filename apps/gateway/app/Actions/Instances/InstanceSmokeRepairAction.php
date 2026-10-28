<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\RequireTasksExtensionAction;
use App\Data\Instances\InstanceSmokeRepairData;
use App\Data\Instances\InstanceSmokeRepairEntryData;
use App\Data\Instances\InstanceSmokeRepairLookupData;
use App\Data\Tasks\CreateTaskGroupData;
use App\Data\Tasks\TaskInputData;
use App\Domain\Instances\Deployment\AutomaticDeployState;
use App\Domain\Instances\Deployment\InstanceSmokeRepairBrief;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

final readonly class InstanceSmokeRepairAction
{
    public function __construct(private InstanceEnvironmentOperationLock $lock, private InstanceSmokeRecoveryAction $recovery,
        private InstanceSmokeRepairBrief $brief, private CreateTaskGroupAction $create, private RequireTasksExtensionAction $tasks) {}

    public function execute(Instance $instance, Node $caller, string $failureKey, string $key): InstanceSmokeRepairData
    {
        if ($key !== $failureKey.'.repair') {
            throw new ResourceOperationException('instance.smoke_repair_invalid', 'The repair key must match the failure.');
        }
        $existing = $this->receipt($instance, $caller, $key)->repair;
        if ($existing !== null) {
            return $existing;
        }
        $this->tasks->execute();
        $failure = $this->recovery->failureReceipt($instance, $caller, $failureKey)->block?->failure;
        if ($failure === null) {
            throw new ResourceOperationException('instance.smoke_repair_invalid', 'Repair requires a recorded verified smoke failure.');
        }
        $this->recovery->proof($instance, $caller, $failure->selection(), $failure->smokeRequestKey, false);
        // Remote context reads never hold the Instance writer lock or the task creation transaction.
        $brief = $this->brief->render($instance, $caller, $failureKey, $failure);

        return $this->lock->run([$instance->id], fn (): InstanceSmokeRepairData => DB::transaction(function () use ($instance, $caller, $failureKey, $key, $failure, $brief): InstanceSmokeRepairData {
            $instance = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $existing = $this->receipt($instance, $caller, $key)->repair;
            if ($existing !== null) {
                return $existing;
            }
            $recorded = $this->recovery->failureReceipt($instance, $caller, $failureKey)->block?->failure;
            if ($recorded?->toArray() !== $failure->toArray() || ! in_array($failure->commit, AutomaticDeployState::of($instance)->blocked(), true)) {
                throw new ResourceOperationException('instance.smoke_repair_invalid', 'Failure identity or permanent block changed.');
            }
            $this->recovery->proof($instance, $caller, $failure->selection(), $failure->smokeRequestKey, false);
            $title = 'Repair production smoke for deployment '.$failure->deploymentId;
            $deliverables = [['id' => 'smoke-regression', 'type' => 'review', 'description' => 'Add a regression test and missing browser smoke coverage. Keep migrations compatible with the retained release.']];
            $check = $instance->project->taskCheckCommand();
            if ($check !== null) {
                $deliverables[] = ['id' => 'project-check', 'type' => 'command', 'description' => 'Pass the configured Project check.', 'command' => $check, 'directory' => '.'];
            }
            $group = $this->create->execute(new CreateTaskGroupData($instance->project_id, $title, $brief,
                TaskGroupStatus::Todo, [new TaskInputData($title, $brief, $deliverables)], orchestration: TaskOrchestration::External));
            $repair = new InstanceSmokeRepairData($key, $instance->id, $instance->project_id, $failureKey, $group->id, $group->tasks->sole()->id);
            $state = AutomaticDeployState::of($instance);
            $state->recordSmokeRepair(new InstanceSmokeRepairEntryData($caller->id, $repair));
            $state->save($instance);

            return $repair;
        }));
    }

    public function receipt(Instance $instance, Node $caller, string $key): InstanceSmokeRepairLookupData
    {
        $owner = preg_match('/\Asmoke-failure\.([1-9][0-9]*)\.[1-9][0-9]*\.repair\z/D', $key, $match) === 1 ? Instance::query()->find($match[1]) : null;
        $entry = $owner === null ? null : AutomaticDeployState::of($owner)->smokeRepair($key);
        if ($entry !== null && ($entry->callerNodeId !== $caller->id || $entry->repair->instanceId !== $instance->id)) {
            throw new ResourceOperationException('operation.idempotency_conflict', 'The repair key belongs to another caller or Instance.', 409);
        }

        return new InstanceSmokeRepairLookupData($key, $entry !== null, $entry?->repair);
    }
}
