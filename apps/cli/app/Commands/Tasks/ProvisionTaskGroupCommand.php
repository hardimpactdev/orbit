<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Tasks\ProvisionTaskGroupRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** Starts a `tasks.provision` operation and follows it until it ends. Ctrl-C stops following, not provisioning. */
final class ProvisionTaskGroupCommand extends TaskCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'tasks:provision
        {group? : Numeric task group ID}
        {--idempotency-key= : Reuse a key to retry this operation start}
        {--json : Return the final operation as machine-readable JSON}';

    #[\Override]
    protected $description = 'Provision the VM and workspace of an external task group.';

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
        $groupId ??= $this->selectGroup($connector, ['todo']);
        if ($groupId === null) {
            return self::FAILURE;
        }

        $key = $this->option('idempotency-key');
        $operation = $this->send($connector, new ProvisionTaskGroupRequest($groupId, is_string($key) ? $key : null), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Provision task group [{$groupId}]", 'Provision')
            : self::FAILURE;
    }
}
