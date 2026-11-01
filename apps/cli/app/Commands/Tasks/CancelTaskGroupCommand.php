<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\Tasks\Concerns\ConfirmsTaskChanges;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tasks\CancelTaskGroupRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Orbit\Sdk\Responses\Tasks\TaskGroupResponse;

final class CancelTaskGroupCommand extends TaskCommand
{
    use ConfirmsTaskChanges;
    use FollowsOperations;

    #[\Override]
    protected $signature = 'tasks:cancel
        {group? : Numeric task group ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--yes : Confirm without prompting}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Cancel a task group and remove its Instance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');

        if ($groupId === false) {
            return self::FAILURE;
        }

        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $connector = $connectors->make($profile);
        $groupId ??= $this->selectGroup($connector, excluded: ['settling', 'waiting_for_review', 'completed']);

        if ($groupId === null) {
            return self::FAILURE;
        }

        $consented = $this->consent(function () use ($connector, $groupId): ?string {
            $group = $this->loadGroup($connector, $groupId);

            if (! $group instanceof TaskGroupResponse) {
                return null;
            }

            return $group->taskableId === null
                ? "Cancel task group {$group->reference()} ({$group->title})?"
                : "Cancel task group {$group->reference()} ({$group->title}) and remove Instance {$group->taskableId}?";
        }, "Task group [{$groupId}] was not cancelled.");

        if (! $consented) {
            return self::FAILURE;
        }

        $key = $this->option('idempotency-key');
        $key = is_string($key) && $key !== '' ? $key : 'tasks.cancel.'.Str::uuid();
        $operation = $this->send($connector, new CancelTaskGroupRequest($groupId, $key), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Cancel task group [{$groupId}]", 'Cancel')
            : self::FAILURE;
    }
}
