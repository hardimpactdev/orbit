<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\Operations\FleetVersionReport;
use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use App\Services\Gateway\GatewayImageReference;
use RuntimeException;

/**
 * Resolves the installed Orbit artifact identity for the gateway and selected
 * workload nodes without shelling into each node during the check phase.
 */
final readonly class FleetVersionProbe
{
    public function __construct(
        private FleetUpdateTargetSelector $targets,
    ) {}

    public function probe(OperationRun $operationRun, OperationUpdatePlan $plan): FleetVersionReport
    {
        $target = $plan->target_version;
        $gatewayVersion = $this->gatewayVersion();

        $nodeVersions = [];
        $outdated = 0;

        if ($this->gatewayNeedsUpdate($plan)) {
            $outdated++;
        }

        foreach ($this->targets->workloadNodes() as $node) {
            $nodeVersions[$node->name] = $this->nodeVersion($node, $operationRun);

            if ($this->nodeNeedsCliUpdate($node, $plan)) {
                $outdated++;
            }
        }

        return new FleetVersionReport(
            targetVersion: $target,
            gatewayVersion: $gatewayVersion,
            nodeVersions: $nodeVersions,
            outdatedCount: $outdated,
        );
    }

    /**
     * Resolve the gateway's currently tracked Orbit version, or `null` when it
     * is unknown. Gateways without a node record fall back to the baked app
     * version so local test and bootstrap contexts can still short-circuit.
     */
    public function gatewayVersion(): ?string
    {
        $gatewayNode = $this->targets->gatewayNode();

        if ($gatewayNode instanceof Node) {
            return $this->normalize((string) $gatewayNode->installed_gateway_image?->version);
        }

        return $this->normalize((string) config('app.version', '0.0.0'));
    }

    /**
     * Resolve a workload node's current tracked Orbit version.
     */
    public function nodeVersion(Node $node, OperationRun $operationRun): ?string
    {
        return $this->normalize((string) $node->installed_cli?->version);
    }

    public function isCurrent(?string $version, string $target): bool
    {
        return $version !== null && version_compare($version, $target, '==');
    }

    public function gatewayNeedsUpdate(OperationUpdatePlan $plan): bool
    {
        $gatewayNode = $this->targets->gatewayNode();

        if (! $gatewayNode instanceof Node) {
            return ! $this->isCurrent($this->gatewayVersion(), $plan->target_version);
        }

        $installed = $gatewayNode->installed_gateway_image;

        if ($installed === null) {
            return true;
        }

        $targetImage = GatewayImageReference::fromString($plan->gateway_image);

        return ! $installed->matches(
            version: $plan->target_version,
            image: $targetImage->canonical(),
            digest: $targetImage->digest(),
        );
    }

    public function nodeNeedsCliUpdate(Node $node, OperationUpdatePlan $plan): bool
    {
        $installed = $node->installed_cli;

        if ($installed === null) {
            return true;
        }

        $artifact = $this->cliArtifact($plan, CliArtifactPlatform::forNode($node));

        return ! $installed->matches(
            version: $plan->target_version,
            platform: $artifact['platform'],
            sha256: $artifact['sha256'],
        );
    }

    /**
     * @return array{platform: string, sha256: string}
     */
    private function cliArtifact(OperationUpdatePlan $plan, string $platform): array
    {
        $artifact = $plan->cli_artifacts[$platform] ?? null;

        if (! is_array($artifact) || ! is_string($artifact['sha256'] ?? null)) {
            throw new RuntimeException("Update plan does not contain a CLI artifact for platform [{$platform}].");
        }

        return [
            'platform' => $platform,
            'sha256' => strtolower($artifact['sha256']),
        ];
    }

    private function normalize(string $version): ?string
    {
        $version = ltrim(trim($version), 'v');

        if ($version === '' || $version === '0.0.0') {
            return null;
        }

        return $version;
    }
}
