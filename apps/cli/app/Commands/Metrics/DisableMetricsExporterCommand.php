<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\DisableMetricsExporterRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class DisableMetricsExporterCommand extends MetricsCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'metrics:exporter:disable {node : Node ID or name} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Disable the Metrics exporter on a node.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->connector($repository, $factory);
        if ($connector === null) {
            return self::FAILURE;
        }
        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));
        if ($nodeId === null) {
            return self::FAILURE;
        }
        $response = $this->sendWithProgress(
            $connector,
            new DisableMetricsExporterRequest($nodeId),
            OperationResponse::class,
            ['Disable Metrics exporter', 'Requesting exporter disable', 'Exporter disable accepted'],
        );

        if (! $response instanceof OperationResponse) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE : $this->followOperation($connector, $profile, $response, 'Metrics exporter', 'Exporter');
    }
}
