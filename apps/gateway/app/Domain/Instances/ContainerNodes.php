<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Support\Facades\Config;

/**
 * The build list `instances.container_nodes`: the only Nodes that run container Instances until every `app-dev` Node
 * switches (docs/solutions/development-container-runtime.md#rules-for-every-pr). A Node that is not on it keeps its
 * checkout Instances. A malformed list refuses, so a typo never puts a Node on it.
 */
final readonly class ContainerNodes
{
    public function includes(Node $node): bool
    {
        return in_array($node->id, $this->ids(), true);
    }

    /** @return list<int> */
    public function ids(): array
    {
        $ids = Config::get('instances.container_nodes');
        if (! is_array($ids) || ! array_is_list($ids) || ! array_all($ids, static fn (mixed $id): bool => is_int($id) && $id > 0)) {
            throw new ResourceOperationException('instance.container_nodes_invalid', 'ORBIT_INSTANCES_CONTAINER_NODES must be a JSON list of Node ids, such as [12,14].', 500);
        }

        return $ids;
    }
}
