<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayConnector;

abstract class MetricsCommand extends GatewayCommand
{
    protected function connector(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $factory,
    ): ?GatewayConnector {
        return $this->gatewayConnector($repository, $factory);
    }

    protected function validationFailure(string $field, string $message): int
    {
        return $this->renderGatewayFailure(
            'metrics.node_required',
            $message,
            details: ['field' => $field],
        );
    }
}
