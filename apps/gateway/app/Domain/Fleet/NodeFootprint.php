<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\NodeFootprint as NodeFootprintRecord;
use Illuminate\Container\Attributes\Tag;

/**
 * The Gateway-rendered Orbit footprint of a managed Node (ADR 0202): its Caddyfile, Node agent
 * configuration, private-DNS listener on the `vpn` Node, and the artifacts
 * that extensions contribute. Re-applying it never changes an Instance, a Process unit, or a role,
 * and never runs `node:add`.
 */
final readonly class NodeFootprint
{
    /** @param iterable<NodeFootprintArtifact> $artifacts Core's artifacts, then contributions, in apply order. */
    public function __construct(
        private AgentDelivery $delivery,
        #[Tag(NodeFootprintArtifact::class)] private iterable $artifacts,
    ) {}

    /** What the Gateway renders for the Node now. It never reads the Node. */
    public function expected(Node $node): NodeFootprintPlan
    {
        $digests = [];

        foreach ($this->artifacts as $artifact) {
            try {
                if ($artifact->applies($node)) {
                    $digests[$artifact->name()] = $this->artifactDigest($artifact, $node);
                }
            } catch (\Throwable $exception) {
                // A digest that cannot be computed never matches, so the next converge reports the failure.
                $digests[$artifact->name()] = 'unavailable:'.($exception instanceof ResourceOperationException ? $exception->errorCode : $exception::class);
            }
        }

        return NodeFootprintPlan::of($digests);
    }

    private function artifactDigest(NodeFootprintArtifact $artifact, Node $node): string
    {
        return hash('sha256', $this->delivery->pin($node)."\0".$artifact->digest($node));
    }

    /** The footprint the Node last received, or null when it never received one. */
    public function recorded(Node $node): ?NodeFootprintRecord
    {
        return NodeFootprintRecord::query()->where('node_id', $node->id)->first();
    }

    /** Whether the Node's recorded footprint differs from what the Gateway renders now. */
    public function drifted(Node $node): bool
    {
        return $this->recorded($node)?->digest !== $this->expected($node)->digest();
    }
}
