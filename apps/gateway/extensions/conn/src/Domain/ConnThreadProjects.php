<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Actions\Projects\ListProjectsAction;
use App\Domain\Instances\DefaultInstanceName;
use App\Domain\Instances\DevelopmentInstanceCreateAdmission;
use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\NativeInstanceProvisioningIntent;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Orbit\Extensions\Conn\Data\ConnProjectData;

/**
 * The reachable Project directory and the read-only admission shared by Conn create and name check.
 * Host bridge and shared-folder readiness stay in create's InstanceContainer::reserve().
 */
final readonly class ConnThreadProjects
{
    public function __construct(
        private NodeAccessAuthorizer $access,
        private ConnInstanceNames $names,
        private ListProjectsAction $projects,
        private DevelopmentInstanceCreateAdmission $admission,
        private ConnInstanceDomains $domains,
    ) {}

    /** @return Collection<int, ConnProjectData> */
    public function all(Node $caller): Collection
    {
        $projects = $this->projects->handle($caller);
        $projects->load(['instances' => static fn (HasMany $query): HasMany => $query
            ->where('name', DefaultInstanceName::Name)->with(['runtime', 'node.roles', 'node.liveTaskVm', 'node.cluster'])]);
        $reachable = $this->access->accessibleNodeIds($caller);
        $runtimeRefusal = $this->runtimeRefusal();

        return $projects->map(function (Project $project) use ($caller, $reachable, $runtimeRefusal): ConnProjectData {
            $default = $project->instances->first();
            $visible = $default !== null && in_array($default->node_id, $reachable, true) ? $default : null;
            $reason = null;
            try {
                $this->assertCloneable($caller, $project, $default, $reachable, $runtimeRefusal);
            } catch (ResourceOperationException $exception) {
                $reason = $exception->errorCode;
            }

            return ConnProjectData::fromProject($project, $visible, $this->domains->suffix($project, $visible), $reason);
        });
    }

    /** Access is checked before readiness, so the caller learns no name or readiness facts on a hidden default. */
    public function default(Node $caller, Project $project): Instance
    {
        $default = Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)
            ->with(['runtime', 'node.roles', 'node.liveTaskVm'])->first();

        return $this->assertCloneable($caller, $project, $default, $this->access->accessibleNodeIds($caller), $this->runtimeRefusal());
    }

    /** @param list<int> $reachable */
    private function assertCloneable(Node $caller, Project $project, ?Instance $default, array $reachable, ?ResourceOperationException $runtimeRefusal): Instance
    {
        if ($default !== null && ! in_array($default->node_id, $reachable, true)) {
            throw new ResourceOperationException('node_access.required', 'Node access is required.', 403, details: [
                'consumer_node' => ['id' => $caller->id, 'name' => $caller->name],
                'serving_node' => ['id' => $default->node->id, 'name' => $default->node->name],
            ]);
        }
        $default = $this->admission->readyDefault($project, $default);
        $this->admission->assertSourceAccess($project);
        $this->admission->assertProject($project, $default->node);
        app(InstanceContainer::class)->assertPhp($project);
        if ($runtimeRefusal !== null) {
            throw $runtimeRefusal;
        }
        app(NativeInstanceProvisioningIntent::class)->assertCreateSupported($default->node);

        return $default;
    }

    private function runtimeRefusal(): ?ResourceOperationException
    {
        try {
            $this->admission->assertRuntime();

            return null;
        } catch (ResourceOperationException $exception) {
            return $exception;
        }
    }

    /**
     * A name already in the Project resumes that Instance's create. Only the device whose create holds the name may resume it, so a
     * thread never lands in another device's clone.
     */
    public function assertNameFree(Node $caller, Project $project, string $name): void
    {
        $existing = $this->names->occupied($project, $name);
        if ($existing !== null && ! Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')
            ->where('target_id', $existing)->where('triggered_by', 'node:'.$caller->id)->exists()) {
            throw new ResourceOperationException('instance.name_taken', "Project [{$project->slug}] already has an Instance named [{$name}].", 409);
        }
    }
}
