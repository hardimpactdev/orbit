<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Nodes\ProvisioningAgentReadinessProbe;
use RuntimeException;

final readonly class FleetUpdateAgentRestartReadiness
{
    public function __construct(
        private FleetUpdateTargetSelector $targets,
        private ProvisioningAgentReadinessProbe $agentReadiness,
    ) {}

    public function wait(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $agentArtifacts = $plan->agent_artifacts ?? [];

        if ($agentArtifacts === []) {
            return;
        }

        $settleMilliseconds = max(
            0,
            (int) config('orbit.updates.agent_restart_settle_milliseconds', default: 6000),
        );

        if ($settleMilliseconds > 0) {
            usleep($settleMilliseconds * 1000);
        }

        $skips = app(FleetUpdatePreMutationSkipRegistry::class);

        foreach ($this->targets->workloadNodes($operationRun) as $node) {
            if (! $node->isFleetUpdateEligible() || $skips->skipped($operationRun->id, $node->name)) {
                continue;
            }

            $platform = CliArtifactPlatform::forNode($node);

            if (! is_array($agentArtifacts[$platform] ?? null)) {
                continue;
            }

            try {
                $this->agentReadiness->waitUntilReady($node);
            } catch (RuntimeException) {
                throw new FleetUpdateVerificationFailed(
                    'agent_readiness_failed',
                    "Orbit Agent readiness verification failed on node {$node->name}.",
                );
            }
        }
    }
}
