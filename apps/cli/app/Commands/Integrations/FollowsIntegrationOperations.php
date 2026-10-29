<?php

declare(strict_types=1);

namespace App\Commands\Integrations;

use App\Commands\Concerns\FollowsOperations;
use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

/** @phpstan-require-extends GatewayCommand */
trait FollowsIntegrationOperations
{
    use FollowsOperations;

    protected function integrationOperation(GatewayConnector $connector, GatewayConfigRepository $repository, GatewayRequest $request, string $title): int
    {
        $profile = $this->activeGatewayProfile($repository);
        if ($profile === null) {
            return self::FAILURE;
        }
        $operation = $this->send($connector, $request, OperationResponse::class);

        return $operation instanceof OperationResponse ? $this->followOperation($connector, $profile, $operation, $title, 'Integration') : self::FAILURE;
    }
}
