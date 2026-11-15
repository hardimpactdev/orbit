<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\WaitsForActiveInstance;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\StartTaskRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/**
 * Starts a task as a copy of its Project's default Instance and, unless --no-wait, waits until the copy is `active`: the same wait as
 * `instance:create --wait-active`. Ctrl-C stops following, not the start.
 */
final class ProvisionTaskGroupCommand extends TaskCommand
{
    use WaitsForActiveInstance;

    #[\Override]
    protected $signature = 'task:start
        {task? : Numeric Task ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--no-wait : Return when the create of the copy ends, before the copy is active}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Start a task as a copy of its Project\'s default Instance, and wait until the copy is active.';

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
        $groupId ??= $this->selectGroup($connector, ['todo']);
        if ($groupId === null) {
            return self::FAILURE;
        }

        $key = $this->option('idempotency-key');
        $operation = $this->send($connector, new StartTaskRequest($groupId, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followCreate($connector, $profile, $operation, "Provision task group [{$groupId}]", 'Provision')
            : self::FAILURE;
    }

    protected function waitsForActive(): bool
    {
        return $this->option('no-wait') !== true;
    }
}
