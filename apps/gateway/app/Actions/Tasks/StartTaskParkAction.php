<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskParkIntent;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;
use App\Support\ValidatedData;

final readonly class StartTaskParkAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations, private TaskParkIntent $intent) {}

    public function execute(Task $group, string $triggeredBy, ?string $requestId): Operation
    {
        $this->extension->execute();

        return $this->operations->start($group, OperationKinds::TasksPark, [], static function (Task $group): void {
            TaskParkIntent::requireEligible($group);
        }, $triggeredBy, $requestId, function (Task $current): array {
            $input = $this->intent->capture($current);
            TaskVm::query()->whereKey($input['task_vm_id'])->update(['state' => TaskVmState::Parked, 'stop_confirmed_at' => null, ...TaskVm::ForgetTopology]);

            return ['node_id' => ValidatedData::integer($input['node_id']), 'input' => $input];
        });
    }
}
