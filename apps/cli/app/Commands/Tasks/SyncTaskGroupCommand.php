<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tasks\SyncTaskGroupRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class SyncTaskGroupCommand extends TaskCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'task:sync
        {task? : Numeric Task ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Bring the Project\'s default branch into a task group\'s copy as refs/remotes/origin/<default branch>.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('task', 'Task', 'task');
        if ($groupId === false) {
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
        // The route requires a key; a retry with the same key replays the Operation.
        $key = $this->option('idempotency-key');
        $key = is_string($key) && $key !== '' ? $key : 'tasks.sync.'.Str::uuid();
        $operation = $this->send($connector, new SyncTaskGroupRequest($groupId, $key), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Sync task group [{$groupId}]", 'Sync')
            : self::FAILURE;
    }
}
