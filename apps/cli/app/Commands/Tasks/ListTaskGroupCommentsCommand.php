<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ListTaskGroupCommentsRequest;
use Orbit\Sdk\Responses\Tasks\TaskCommentsResponse;

final class ListTaskGroupCommentsCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:group:comment:list
        {group? : Numeric task group ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List group-owned comments, newest first, including after completion.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');

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

        $comments = $this->sendWithProgress($connector, new ListTaskGroupCommentsRequest($groupId), TaskCommentsResponse::class, ['List comments', 'Loading comments', 'Loaded comments']);

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
