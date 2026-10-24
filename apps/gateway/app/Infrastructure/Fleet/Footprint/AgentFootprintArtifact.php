<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

/**
 * The Node agent's unit, configuration, root certificate, and pinned binary, converged by the same
 * step as `node:add` and role converge. The digest covers every Gateway input the converge renders
 * from: the Gateway address, the root certificate, the managed user, and the Node settings.
 * NodeFootprint adds the delivered binary pin to this digest, as it does for every artifact.
 */
final readonly class AgentFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(
        private NodeAgentRuntime $agent,
        private ManagedNodeEligibility $eligibility,
        private LeafCertificateSigner $certificates,
    ) {}

    public function name(): string
    {
        return 'agent';
    }

    public function applies(Node $node): bool
    {
        return $this->eligibility->allows($node);
    }

    public function digest(Node $node): string
    {
        return hash('sha256', json_encode([
            'gateway' => Node::query()
                ->where('status', LifecycleStatus::Active)
                ->whereHas('roles', static fn ($query) => $query->where('role', RoleName::Gateway)->where('status', LifecycleStatus::Active))
                ->value('wireguard_ip'),
            'certificate' => hash('sha256', $this->certificates->rootCertificate()),
            'user' => $node->user,
            'settings' => $node->settings,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function apply(Node $node): ?bool
    {
        $this->agent->converge($node);

        return null;
    }
}
