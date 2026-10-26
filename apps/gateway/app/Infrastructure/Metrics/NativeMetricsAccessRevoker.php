<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Models\NodeRole;

/**
 * Reloads the Gateway's Caddy after Node access changes, so an open Metrics connection authorizes again.
 * It holds the Node Caddy lock, so the reload never interleaves with a build's swap and restore.
 */
final readonly class NativeMetricsAccessRevoker implements MetricsAccessRevoker
{
    public function __construct(
        private AgentCommandRunner $agents,
        private MetricsGatewayResolver $gateways = new MetricsGatewayResolver,
    ) {}

    public function revoke(): void
    {
        $metricsMayBePublished = NodeRole::query()
            ->where('role', RoleName::Metrics->value)
            ->exists();

        if (! $metricsMayBePublished) {
            return;
        }

        try {
            $this->agents->run($this->gateways->resolve(), 'caddy.reload');
        } catch (AgentCommandRefused|AgentRunnerFailure $exception) {
            throw new ResourceOperationException(
                'metrics.caddy_publication_failed',
                'Metrics Caddy publication did not complete.',
                502,
                previous: $exception,
            );
        }
    }
}
