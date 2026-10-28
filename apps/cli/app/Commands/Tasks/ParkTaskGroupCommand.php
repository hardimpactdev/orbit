<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Tasks\ParkTaskGroupRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Starts a `tasks.park` operation and follows it until it ends. Ctrl-C stops following, not parking. */
final class ParkTaskGroupCommand extends TaskCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'tasks:park
        {group? : Numeric task group ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Park an external task group VM while its PR waits for review.';

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
        $groupId ??= $this->selectGroup($connector, ['running', 'reviewing', 'settling', 'waiting_for_review']);
        if ($groupId === null) {
            return self::FAILURE;
        }

        $key = $this->option('idempotency-key');
        $key = is_string($key) && $key !== '' ? $key : 'tasks.park.'.Str::uuid();
        $operation = $this->send($connector, new ParkTaskGroupRequest($groupId, $key), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Park task group [{$groupId}]", 'Park')
            : self::FAILURE;
    }
}
