<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\ReadyContainerDefault;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
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
        $query = ReadyContainerDefault::query()->with(['project', 'node']);
        if (! $this->access->hasGatewayAuthority($caller)) {
            $query->whereIn('node_id', $this->access->accessibleNodeIds($caller));
        }

        return $query->get()->sortBy(static fn (Instance $default): string => $default->project->slug)->values();
    }

    /**
     * The Project's ready default, which the caller must reach to start a thread in a clone of it. Access is checked first, so a
     * caller without it learns nothing about the default.
     */
    public function default(Node $caller, Project $project): Instance
    {
        $default = Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->with('node')->first();
        if ($default instanceof Instance && ! $this->access->allows($caller, $default->node)) {
            throw new ResourceOperationException('node_access.required', 'Node access is required.', 403, details: [
                'consumer_node' => ['id' => $caller->id, 'name' => $caller->name],
                'serving_node' => ['id' => $default->node->id, 'name' => $default->node->name],
            ]);
        }
        if (! $default instanceof Instance || ! ReadyContainerDefault::query()->whereKey($default->id)->exists()) {
            throw new ResourceOperationException('instance.default_missing',
                "Project [{$project->slug}] has no default Instance in a container that is active and has a seed snapshot.", 409);
        }

        return $default;
    }

    /**
     * A name already in the Project resumes that Instance's create. Only the device whose create holds the name may resume it, so a
     * thread never lands in another device's clone.
     */
    public function assertNameFree(Node $caller, Project $project, string $name): void
    {
        $existing = Instance::query()->where('project_id', $project->id)->where('name', $name)->value('id');
        if ($existing !== null && ! Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')
            ->where('target_id', $existing)->where('triggered_by', 'node:'.$caller->id)->exists()) {
            throw new ResourceOperationException('instance.name_taken', "Project [{$project->slug}] already has an Instance named [{$name}].", 409);
        }
    }
}
