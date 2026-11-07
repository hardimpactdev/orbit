<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ShowTaskRequest;
use Orbit\Sdk\Responses\Tasks\SubtaskResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;
use Orbit\Sdk\Responses\Tasks\TaskRecordResponse;

final class ShowTaskGroupCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'task:show
        {task? : Numeric Task ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show a task group with its brief and subtasks.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('task', 'Task', 'task');

        if ($groupId === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $groupId ??= $this->selectGroup($connector);

        if ($groupId === null) {
            return self::FAILURE;
        }

        $group = $this->sendWithProgress($connector, new ShowTaskRequest($groupId), TaskRecordResponse::class, ['Show task group', 'Loading task group', 'Loaded task group']);

        return $group instanceof SubtaskResponse ? $this->renderSubtask($group) : ($group instanceof TaskGroupResponse ? $this->renderGroup($group) : self::FAILURE);
    }
}
