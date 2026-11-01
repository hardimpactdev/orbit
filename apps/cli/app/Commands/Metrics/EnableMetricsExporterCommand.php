<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\Concerns\FollowsOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\EnableMetricsExporterRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;

final class EnableMetricsExporterCommand extends MetricsCommand
{
    use FollowsOperations;

    #[\Override]
    protected $signature = 'metrics:exporter:enable {node : Node ID or name} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable the Metrics exporter on a node.';

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
            new EnableMetricsExporterRequest($nodeId),
            OperationResponse::class,
            ['Enable Metrics exporter', 'Requesting exporter enable', 'Exporter enable accepted'],
        );

        if (! $response instanceof OperationResponse) {
            return self::FAILURE;
        }
        $profile = $this->activeGatewayProfile($repository);

        return $profile === null ? self::FAILURE : $this->followOperation($connector, $profile, $response, 'Metrics exporter', 'Exporter');
    }
}
