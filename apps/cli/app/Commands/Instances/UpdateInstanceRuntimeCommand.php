<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Instances\UpdateInstanceRuntimeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class UpdateInstanceRuntimeCommand extends GatewayCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'instance:runtime:update
        {instance : Numeric Instance ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Prepare one Instance’s private Node store without restarting Processes.';

    protected function writeOperationResult(OperationResponse $operation): void
    {
        $status = $operation->result['status'] ?? null;
        $versions = $operation->result['versions'] ?? [];
        if (is_string($status)) {
            $versions = is_array($versions) ? array_filter($versions, is_string(...)) : [];
            $this->writeHumanMessage('Runtime store: '.$status.'. Node: '.implode(', ', $versions).'.');
        }
    }

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');
        if ($instanceId === null) {
            return self::FAILURE;
        }
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, new UpdateInstanceRuntimeRequest($instanceId, bin2hex(random_bytes(16))), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Update Instance runtime', 'Instance runtime update')
            : self::FAILURE;
    }
}
