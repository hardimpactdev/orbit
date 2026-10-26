<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\TaskWorkerData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskProvisionOperation;
use App\Infrastructure\TaskVms\TaskWorkerEnrollment;
use App\Models\Operation;
use App\Models\Task;
use SensitiveParameter;
use Throwable;

final readonly class StartTaskProvisionAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskOperations $operations, private TaskWorkerEnrollment $enrollment) {}

    public function execute(Task $group, string $triggeredBy, ?string $requestId, #[SensitiveParameter] ?TaskWorkerData $worker = null): Operation
    {
        $this->extension->execute();

        $reference = $worker === null ? null : $this->enrollment->stage($worker->enrollment_token);
        $input = $worker === null ? [] : ['worker' => [
            'orchestrator_url' => $worker->orchestrator_url, 'labels' => $worker->labels, 'enrollment_reference' => $reference,
        ]];
        try {
            $operation = $this->operations->start($group, OperationKinds::TasksProvision, $input, TaskProvisionOperation::requireTodo(...), $triggeredBy, $requestId);
        } catch (Throwable $exception) {
            if ($reference !== null) {
                $this->enrollment->forget($reference);
            }
            throw $exception;
        }
        $recorded = $operation->input['worker'] ?? null;
        if ($reference !== null && (! is_array($recorded) || ($recorded['enrollment_reference'] ?? null) !== $reference)) {
            $this->enrollment->forget($reference);
        }

        return $operation;
    }
}
