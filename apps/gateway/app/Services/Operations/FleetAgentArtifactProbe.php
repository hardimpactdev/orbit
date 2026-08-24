<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use App\Models\OperationUpdatePlan;
use RuntimeException;

final readonly class FleetAgentArtifactProbe
{
    public function __construct(
        private InstalledArtifactRunStatus $installedArtifactRuns,
    ) {}

    public function nodeNeedsUpdate(Node $node, OperationUpdatePlan $plan): bool
    {
        if (! $node->isFleetUpdateEligible()) {
            return false;
        }

        $artifact = $this->artifact($plan, CliArtifactPlatform::forNode($node));

        if ($artifact === null) {
            return false;
        }

        $installed = $node->installed_agent;

        if ($installed === null) {
            return true;
        }

        if (! $this->installedArtifactRuns->isTrusted($installed->operationRunId)) {
            return true;
        }

        return ! $installed->matches(
            version: $plan->target_version,
            platform: $artifact['platform'],
            sha256: $artifact['sha256'],
        );
    }

    /**
     * @return array{platform: string, sha256: string}|null
     */
    private function artifact(OperationUpdatePlan $plan, string $platform): ?array
    {
        $agentArtifacts = $plan->agent_artifacts ?? [];
        $artifact = $agentArtifacts[$platform] ?? null;

        if ($artifact === null) {
            return null;
        }

        if (! is_array($artifact) || ! is_string($artifact['sha256'] ?? null)) {
            throw new RuntimeException("Update plan contains an invalid agent artifact for platform [{$platform}].");
        }

        return [
            'platform' => $platform,
            'sha256' => strtolower($artifact['sha256']),
        ];
    }
}
