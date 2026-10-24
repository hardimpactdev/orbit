<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NativeMetricsContainerRuntime implements MetricsRuntimeLifecycle
{
    public function __construct(
        private MetricsRuntimeCommands $commands,
        private MetricsConfigurationRenderer $configurations,
        private MetricsCredentialManager $credentials,
        private MetricsExporterLifecycle $exporters,
        private ?ServiceMetricsProjection $services = null,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $password = $this->credentials->passwordForConvergence($node);
        $configuration = $this->configurations->render($this->exporters->targets($node), $password, $this->services?->forFleet($node) ?? []);
        $this->commands->converge($node, $assignment->id, $configuration);
        $this->credentials->verifyActive($node);
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->commands->remove($node, $purgeData);
        if ($purgeData) {
            $this->credentials->purge($node);
        }
    }

    public function health(Node $node, string $service): bool
    {
        $metricsService = MetricsService::tryFrom($service);

        return $metricsService instanceof MetricsService && $this->commands->health($node, $metricsService);
    }
}
