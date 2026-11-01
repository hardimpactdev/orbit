<?php

declare(strict_types=1);

namespace App\Commands\Environment;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Environment\SynchronizeInstanceEnvironmentRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class SynchronizeEnvironmentCommand extends EnvironmentCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'env:sync
        {--instance= : Positive Instance ID or exact Route domain}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Synchronize stored Instance configuration to the workload environment file.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $selector = $this->instanceSelector();

        if ($selector === null) {
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

        $operation = $this->send($connector, new SynchronizeInstanceEnvironmentRequest($selector), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, 'Synchronize environment', 'Environment synchronization')
            : self::FAILURE;
    }
}
