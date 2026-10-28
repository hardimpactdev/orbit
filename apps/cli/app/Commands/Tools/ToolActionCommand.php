<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

abstract class ToolActionCommand extends ToolCommand
{
    use FollowsOperations;

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $toolId = $this->toolId();
        if ($toolId === null) {
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
        $operation = $this->send($connector, $this->request($toolId), OperationResponse::class);

        return $operation instanceof OperationResponse
            ? $this->followOperation($connector, $profile, $operation, "Change Tool #{$toolId}", 'Tool')
            : self::FAILURE;
    }

    abstract protected function request(int $toolId): GatewayRequest;
}
