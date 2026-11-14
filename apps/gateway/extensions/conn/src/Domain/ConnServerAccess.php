<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;
use Orbit\Extensions\Conn\Models\ConnEnvironment;

/**
 * A T3 server belongs to the Node that registered it; a caller reaches it through node access to that Node
 * ([Access](/reference/conn#access)). A server whose Node was removed needs Gateway access.
 */
final readonly class ConnServerAccess
{
    public function __construct(private NodeAccessAuthorizer $access) {}

    /**
     * @param  Builder<ConnEnvironment>  $query
     * @return Builder<ConnEnvironment>
     */
    public function visible(Node $caller, Builder $query): Builder
    {
        return $this->access->hasGatewayAuthority($caller)
            ? $query
            : $query->whereIn('node_id', $this->access->accessibleNodeIds($caller));
    }

    public function allows(Node $caller, ConnEnvironment $environment): bool
    {
        $node = $environment->node;

        return $node instanceof Node ? $this->access->allows($caller, $node) : $this->access->hasGatewayAuthority($caller);
    }

    public function assert(Node $caller, ConnEnvironment $environment): void
    {
        $node = $environment->node;

        if (! $this->allows($caller, $environment)) {
            throw new ResourceOperationException('node_access.required', 'Node access is required.', 403, details: [
                'consumer_node' => ['id' => $caller->id, 'name' => $caller->name],
                'serving_node' => $node instanceof Node ? ['id' => $node->id, 'name' => $node->name] : null,
            ]);
        }
    }
}
