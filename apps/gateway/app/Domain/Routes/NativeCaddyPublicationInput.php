<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Complete public Caddy intent, rendered before native dispatch rather than by a step GET. */
final readonly class NativeCaddyPublicationInput
{
    /** @return array{caddy_publication: array<string,mixed>} */
    public function forOperation(Operation $operation): array
    {
        if ($operation->kind !== 'route.caddy_converge' || $operation->node_id === null) {
            throw new ResourceOperationException('route.publication_invalid', 'Caddy publication requires its admitted Node.', 422);
        }
        return ['caddy_publication' => $this->forNode(Node::query()->findOrFail($operation->node_id))];
    }

    /** @return array<string,mixed> */
    public function forNode(Node $node): array
    {
        if (in_array($node->id, [1, 18], true)) {
            throw new ResourceOperationException('caddy.placement_unavailable', 'Native Caddy publication requires the shared placed shell transport.', 503);
        }
        $publication = CaddyPublicationPlan::forNode($node);
        if (isset($publication['error_code'])) {
            throw new ResourceOperationException('caddy.render_failed', 'The admitted Node Caddy configuration cannot be rendered.', 422);
        }

        return array_intersect_key($publication, array_flip(['node_id', 'user', 'caddy']));
    }
}
