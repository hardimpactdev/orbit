<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The Projects a device can start a thread in ([Threads in an Orbit Project](/reference/conn#threads-in-an-orbit-project)): each one
 * whose default Instance runs in a container, is active and has a seed snapshot, on a Node the device has node access to. A thread
 * starts in a clone of that default, on its Node.
 */
final readonly class ConnThreadProjects
{
    public function __construct(private NodeAccessAuthorizer $access) {}

    /** @return Collection<int, Instance> the ready defaults the caller reaches, by Project slug */
    public function defaults(Node $caller): Collection
    {
        $query = $this->ready()->with(['project', 'node']);
        if (! $this->access->hasGatewayAuthority($caller)) {
            $query->whereIn('node_id', $this->access->accessibleNodeIds($caller));
        }

        return $query->get()->sortBy(static fn (Instance $default): string => $default->project->slug)->values();
    }

    /** The Project's ready default, which the caller must reach to start a thread in a clone of it. */
    public function default(Node $caller, Project $project): Instance
    {
        $default = $this->ready()->where('project_id', $project->id)->with('node')->first();
        if (! $default instanceof Instance) {
            throw new ResourceOperationException('instance.default_missing',
                "Project [{$project->slug}] has no default Instance in a container that is active and has a seed snapshot.", 409);
        }
        if (! $this->access->allows($caller, $default->node)) {
            throw new ResourceOperationException('node_access.required', 'Node access is required.', 403, details: [
                'consumer_node' => ['id' => $caller->id, 'name' => $caller->name],
                'serving_node' => ['id' => $default->node->id, 'name' => $default->node->name],
            ]);
        }

        return $default;
    }

    /** @return Builder<Instance> */
    private function ready(): Builder
    {
        return Instance::query()->where('name', DefaultInstanceName::Name)->where('status', InstanceState::Active)
            ->whereHas('runtime', static fn (Builder $runtime): Builder => $runtime->whereNotNull('seed_commit'));
    }
}
