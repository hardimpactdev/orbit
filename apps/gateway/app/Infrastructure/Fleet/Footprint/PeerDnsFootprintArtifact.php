<?php

declare(strict_types=1);

namespace App\Infrastructure\Fleet\Footprint;

use App\Domain\Fleet\NodeFootprintArtifact;
use App\Domain\WireGuard\WireGuardPeerDnsRepairer;
use App\Infrastructure\WireGuard\PeerDnsInspector;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;

final readonly class PeerDnsFootprintArtifact implements NodeFootprintArtifact
{
    public function __construct(private PeerDnsPolicy $policy, private PeerDnsInspector $inspector, private WireGuardPeerDnsRepairer $repairer) {}

    public function name(): string
    {
        return 'peer-dns';
    }

    public function applies(Node $node): bool
    {
        return $this->policy->manages($node);
    }

    public function digest(Node $node): string
    {
        return hash('sha256', json_encode([$this->policy->domains($node), $this->policy->server($node),
            SourceDigest::of(['app/Infrastructure/WireGuard/PeerDnsPolicy.php', 'app/Infrastructure/WireGuard/NativeWireGuardPeerDnsRepairer.php', 'app/Infrastructure/WireGuard/PeerDnsProgram.php']),
        ], JSON_THROW_ON_ERROR));
    }

    public function apply(Node $node): bool
    {
        if ($this->inspector->inspect($node)['policy']) {
            return false;
        }
        $this->repairer->repair($node);

        return true;
    }
}
