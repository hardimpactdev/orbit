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

    /** @return Collection<int, Project> */
    public function handle(Node $consumer): Collection
    {
        $nodeIds = $this->access->hasGatewayAuthority($consumer) ? null : $this->access->accessibleNodeIds($consumer);
        $reachable = $nodeIds === null ? null : static fn ($instances) => $instances->whereIn('node_id', $nodeIds);

        return Project::query()
            ->when($reachable !== null, fn ($query) => $query->whereHas('instances', $reachable))
            ->with('defaultInstance.node')
            // Count only the Instances the caller can reach, as the list itself filters.
            ->withCount($reachable === null ? 'instances' : ['instances' => $reachable])
            // Alphabetical by name so a list reads as a directory and a picker stays predictable.
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
