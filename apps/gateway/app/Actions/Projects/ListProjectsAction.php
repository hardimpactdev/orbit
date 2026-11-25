<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListProjectsAction
{
    public function __construct(
        private NodeAccessAuthorizer $access,
    ) {}

    /**
     * A generic Project has no Instance, so the caller sees it through the Node it is about (projects.md#generic-projects).
     *
     * @param  int|null  $nodeId  only the Project about this Node, which the Node's ID finds whatever the Node is named
     * @return Collection<int, Project>
     */
    public function handle(Node $consumer, ?int $nodeId = null): Collection
    {
        $nodeIds = $this->reachableNodeIds($consumer);
        $reachable = $nodeIds === null ? null : static fn ($instances) => $instances->whereIn('node_id', $nodeIds);

        return Project::query()
            ->when($reachable !== null, fn ($query) => $query->where(fn ($visible) => $visible
                ->whereHas('instances', $reachable)
                ->orWhereIn('node_id', $nodeIds ?? [])))
            ->when($nodeId !== null, fn ($query) => $query->where('node_id', $nodeId))
            ->with(['defaultInstance.node', 'node'])
            // Count only the Instances the caller can reach, as the list itself filters.
            ->withCount($reachable === null ? 'instances' : ['instances' => $reachable])
            // Alphabetical by name so a list reads as a directory and a picker stays predictable.
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /** @return list<int>|null The Nodes whose Instances the consumer can reach; null for Gateway authority, which reaches every Node. */
    public function reachableNodeIds(Node $consumer): ?array
    {
        return $this->access->hasGatewayAuthority($consumer) ? null : $this->access->accessibleNodeIds($consumer);
    }
}
