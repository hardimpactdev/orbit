<?php

declare(strict_types=1);

namespace App\Commands\Metrics;

use App\Commands\Integrations\FollowsIntegrationOperations;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Metrics\DisableMetricsExporterRequest;

final class DisableMetricsExporterCommand extends MetricsCommand
{
    use FollowsIntegrationOperations;

    #[\Override]
    protected $signature = 'metrics:exporter:disable {node : Node ID or name} {--idempotency-key= : Reuse a key to retry this operation start} {--json : Return machine-readable JSON}';

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

        return $this->integrationOperation($connector, $repository,
            new DisableMetricsExporterRequest($nodeId, is_string($this->option('idempotency-key')) ? $this->option('idempotency-key') : null), 'Disable Metrics exporter');
    }
}
