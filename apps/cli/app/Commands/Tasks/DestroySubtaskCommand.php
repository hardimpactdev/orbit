<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Tasks\Concerns\ConfirmsTaskChanges;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\DestroyTaskRequest;
use Orbit\Sdk\Requests\Tasks\ShowTaskRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskRecordResponse;

final class DestroySubtaskCommand extends TaskCommand
{
    use ConfirmsTaskChanges;

    #[\Override]
    protected $signature = 'task:destroy
        {task? : Numeric Task ID}
        {--yes : Confirm without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Delete a subtask from a task group in Backlog.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $subtaskId = $this->idArgument('task', 'Task', 'task');
        if ($subtaskId === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $subtaskId ??= (int) $this->promptText('Subtask ID', 20);
        $consented = $this->consent(function () use ($connector, $subtaskId): ?string {
            $task = $this->send($connector, new ShowTaskRequest($subtaskId), TaskRecordResponse::class);

            return $task instanceof SubtaskResponse ? "Destroy subtask [{$task->id}] ({$task->title})?" : null;
        }, "Subtask [{$subtaskId}] was not destroyed.");

        if (! $consented) {
            return self::FAILURE;
        }

        $task = $this->sendWithProgress($connector, new DestroyTaskRequest($subtaskId), SubtaskResponse::class, ['Destroy subtask', 'Destroying subtask', 'Destroyed subtask']);

        if (! $task instanceof SubtaskResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($task->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Subtask [{$task->id}] {$task->title} destroyed.");
        $this->writeHumanMessage("Request ID: {$task->requestId}");

        return self::SUCCESS;
    }
}
