<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Infrastructure\AgentView\AgentReportedVersions;
use App\Models\Node;
use App\Models\NodeFact;

/** Test-fleet readiness: old facts and cached presence must not prove a replacement process. */
final readonly class ProofObserverReadiness
{
    public function __construct(private AgentReportedVersions $versions) {}

    public function reset(): void
    {
        $nodes = Node::query()->whereIn('name', ['gateway', 'app-dev', 'app-prod'])->get();
        NodeFact::query()->whereIn('node_id', $nodes->modelKeys())->where('kind', 'host')->delete();
        foreach ($nodes as $node) {
            $this->versions->forget($node->id);
        }
    }

    public function ready(string $expected): bool
    {
        $nodes = Node::query()->whereIn('name', ['gateway', 'app-dev', 'app-prod'])->get();
        if ($nodes->count() !== 3) {
            return false;
        }
        foreach ($nodes as $node) {
            if (($this->versions->get($node->id)['version'] ?? null) !== $expected
                || ! NodeFact::query()->where('node_id', $node->id)->where('kind', 'host')->exists()) {
                return false;
            }
        }

        return true;
    }
}
