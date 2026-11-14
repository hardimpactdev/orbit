<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\PublishTaskGroupRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class PublishTaskGroupCommand extends TaskCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'task:publish
        {task? : Numeric Task ID}
        {--check= : Passed handoff check ID; omit it with --title and --body to release a task copy\'s done check}
        {--title= : Pull request title (requires --body)}
        {--body= : Pull request body (requires --title)}
        {--draft : Open the pull request as a draft (requires --title and --body)}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Push a checked commit and optionally open or update its pull request, or release a task copy\'s done check.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('task', 'Task', 'task');
        if ($groupId === false) {
            return self::FAILURE;
        }
        // Without --check, the call releases a task copy's done check, so it never asks for one.
        $check = $this->option('check') === null ? null : $this->idOption('check', 'Check', 'check');
        if ($check === false) {
            return self::FAILURE;
        }
        $title = $this->option('title');
        $body = $this->option('body');
        if (($title === null) !== ($body === null) || $title === '' || $body === '') {
            $this->renderGatewayFailure('task.pull_request_invalid', 'Supply --title and --body together.');

            return self::FAILURE;
        }
        if ($check === null && $title !== null) {
            $this->renderGatewayFailure('task.pull_request_invalid', 'Supply --title and --body with --check; without --check Orbit writes them.');

            return self::FAILURE;
        }
        if ($this->option('draft') === true && $title === null) {
            $this->renderGatewayFailure('task.pull_request_invalid', 'Supply --draft with --title and --body.');

            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $connector = $connectors->make($profile);
        $groupId ??= $this->selectGroup($connector, ['running', 'reviewing', 'settling', 'waiting_for_review']);
        if ($groupId === null) {
            return self::FAILURE;
        }
        $key = $this->option('idempotency-key');
        $pullRequest = is_string($title) && is_string($body) ? ['title' => $title, 'body' => $body, ...($this->option('draft') === true ? ['draft' => true] : [])] : null;
        $operation = $this->send($connector, new PublishTaskGroupRequest($groupId, $check, $pullRequest, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Publish task group [{$groupId}]", 'Publish')
            : self::FAILURE;
    }
}
