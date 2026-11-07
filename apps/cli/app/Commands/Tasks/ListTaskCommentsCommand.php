<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ListTaskCommentsRequest;
use Orbit\Sdk\Responses\Tasks\TaskCommentsResponse;

final class ListTaskCommentsCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'task:comment:list
        {task? : Numeric Task ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List a subtask\'s comments, newest first.';

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

        $comments = $this->sendWithProgress($connector, new ListTaskCommentsRequest($groupId), TaskCommentsResponse::class, ['List comments', 'Loading comments', 'Loaded comments']);

        if (! $comments instanceof TaskCommentsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($comments->toArray());

            return self::SUCCESS;
        }

        if ($comments->comments === []) {
            $this->writeHumanMessage('No comments.');
        }

        foreach ($comments->comments as $comment) {
            $this->writeComment($comment);
        }

        $this->writeHumanMessage("Request ID: {$comments->requestId}");

        return self::SUCCESS;
    }
}
