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
use LogicException;
use Orbit\Extensions\Conn\Data\ConnProjectCheckData;
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
    public function all(Node $caller, bool $includeUnavailable = false): Collection
    {
        $projects = $this->projects->handle($caller);
        $projects->load(['instances' => static fn (HasMany $query): HasMany => $query
            ->where('name', DefaultInstanceName::Name)->with(['runtime', 'node.roles', 'node.liveTaskVm', 'node.cluster'])]);
        $reachable = $this->access->accessibleNodeIds($caller);
        $runtimeRefusals = $this->runtimeRefusals();

        return $projects->map(function (Project $project) use ($reachable, $runtimeRefusals): ConnProjectData {
            $default = $project->instances->first();
            $visible = $default !== null && in_array($default->node_id, $reachable, true) ? $default : null;
            $result = $this->checks($project, $default, $reachable, $runtimeRefusals);

            return ConnProjectData::fromProject($project, $visible, $this->domains->suffix($project, $visible),
                $result['refusal']?->errorCode, $result['checks']);
        })->filter(static fn (ConnProjectData $project): bool => $includeUnavailable || array_any(
            $project->checks,
            // This check passes only after the four readiness checks: exactly the pre-directory scope.
            static fn (ConnProjectCheckData $check): bool => $check->key === 'node_access' && $check->status === ConnProjectCheckStatus::Pass,
        ))->values();
    }

    /** Readiness precedes access. Refusals never identify a hidden Node. */
    public function default(Node $caller, Project $project): Instance
    {
        $default = $this->defaultInstance($project);
        $result = $this->checks($project, $default, $this->access->accessibleNodeIds($caller), $this->runtimeRefusals());
        if ($result['refusal'] !== null) {
            throw $result['refusal'];
        }
        assert($default instanceof Instance);

        return $default;
    }

    /** Branch reads need a reachable ready default, without the clone's source, placement or runtime requirements. */
    public function readableDefault(Node $caller, Project $project): Instance
    {
        $default = $this->defaultInstance($project);
        if ($default !== null) {
            $this->assertNodeAccess($default, $this->access->accessibleNodeIds($caller));
        }

        return $this->admission->readyDefault($project, $default);
    }

    private function defaultInstance(Project $project): ?Instance
    {
        return Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)
            ->with(['runtime', 'node.roles', 'node.liveTaskVm'])->first();
    }

    /**
     * @param  list<int>  $reachable
     * @param  array{runtime_contributions: ?ResourceOperationException, model_route: ?ResourceOperationException}  $runtimeRefusals
     * @return array{checks: list<ConnProjectCheckData>, refusal: ?ResourceOperationException}
     */
    private function checks(Project $project, ?Instance $default, array $reachable, array $runtimeRefusals): array
    {
        $instance = fn (): Instance => $default ?? throw new LogicException('A later check needs a default Instance.');
        $node = fn (): Node => $instance()->node;
        $definitions = [
            ['key' => 'default_instance', 'label' => 'Default Instance',
                'message' => "Create this Project's default Instance with instance:create.",
                'check' => fn () => $this->admission->assertDefaultExists($project, $default)],
            ['key' => 'container', 'label' => 'Runs in a container',
                'message' => "Move this Project's default Instance into a container with instance:migrate.",
                'check' => fn () => $this->admission->assertContainer($project, $instance())],
            ['key' => 'active', 'label' => 'Active',
                'message' => "Finish or repair this Project's default Instance before starting a thread.",
                'check' => fn () => $this->admission->assertActive($instance())],
            ['key' => 'seed_snapshot', 'label' => 'Seed snapshot',
                'message' => "Deploy this Project's default Instance to create a seed snapshot.",
                'check' => fn () => $this->admission->assertSeed($instance())],
            ['key' => 'node_access', 'label' => 'Node access',
                'message' => 'Grant this device node access to the Node that hosts the default Instance.',
                'check' => fn () => $this->assertNodeAccess($instance(), $reachable)],
            ['key' => 'source_access', 'label' => 'Source access',
                'message' => 'Use GitHub App source access for this Project in Orbit.',
                'check' => fn () => $this->admission->assertSourceAccess($project)],
            ['key' => 'source_defaults', 'label' => 'Source defaults',
                'message' => 'Set a valid repository, default branch and root for this Project in Orbit.',
                'check' => fn () => $this->admission->assertDefaults($project)],
            ['key' => 'production_placement', 'label' => 'Development placement',
                'message' => 'Use a development Node without an active app-prod role.',
                'check' => fn () => $this->admission->assertProductionPlacement($node())],
            ['key' => 'node_active_linux', 'label' => 'Active Linux Node',
                'message' => "Use an active Linux Node for this Project's default Instance.",
                'check' => fn () => $this->admission->assertNodeActiveLinux($node())],
            ['key' => 'app_dev_role', 'label' => 'App-dev role',
                'message' => "Add the app-dev role to the default Instance's Node.",
                'check' => fn () => $this->admission->assertAppDevRole($node())],
            ['key' => 'source_profile', 'label' => 'Source metadata',
                'message' => "Fix this Project's source metadata in Orbit.",
                'check' => fn () => $this->admission->assertSourceProfile($project)],
            ['key' => 'php_readiness', 'label' => 'PHP readiness',
                'message' => 'Set a supported PHP version for this Project and update its container runtime.',
                'check' => fn () => app(InstanceContainer::class)->assertPhp($project)],
            ['key' => 'runtime_contributions', 'label' => 'Conn runtime',
                'message' => 'Enable Conn in Orbit before starting a thread.',
                'check' => fn () => $this->assertRuntimeCheck($runtimeRefusals['runtime_contributions'])],
            ['key' => 'model_route', 'label' => 'Model router',
                'message' => 'Set up the model router and apply its guard in Orbit.',
                'check' => fn () => $this->assertRuntimeCheck($runtimeRefusals['model_route'])],
            ['key' => 'native_create', 'label' => 'Create support',
                'message' => 'Use an app-dev Node instead of a task VM for this Project.',
                'check' => fn () => app(NativeInstanceProvisioningIntent::class)->assertCreateSupported($node())],
        ];
        $checks = [];
        $refusal = null;
        foreach ($definitions as $definition) {
            $status = ConnProjectCheckStatus::NotChecked;
            $code = $message = null;
            if ($refusal === null) {
                try {
                    $definition['check']();
                    $status = ConnProjectCheckStatus::Pass;
                } catch (ResourceOperationException $exception) {
                    $status = ConnProjectCheckStatus::Fail;
                    $code = $exception->errorCode;
                    $message = $definition['message'];
                    $refusal = new ResourceOperationException($code, $message, $exception->status);
                }
            }
            $checks[] = $status === ConnProjectCheckStatus::Fail
                ? new ConnProjectCheckData($definition['key'], $definition['label'], $status, $code, $message)
                : new ConnProjectCheckData($definition['key'], $definition['label'], $status);
        }

        return ['checks' => $checks, 'refusal' => $refusal];
    }

    /** @param list<int> $reachable */
    private function assertNodeAccess(Instance $default, array $reachable): void
    {
        if (! in_array($default->node_id, $reachable, true)) {
            throw new ResourceOperationException('node_access.required', 'Node access is required.', 403);
        }
    }

    private function assertRuntimeCheck(?ResourceOperationException $refusal): void
    {
        if ($refusal !== null) {
            throw $refusal;
        }
    }

    /** @return array{runtime_contributions: ?ResourceOperationException, model_route: ?ResourceOperationException} */
    private function runtimeRefusals(): array
    {
        $refusals = ['runtime_contributions' => null, 'model_route' => null];
        foreach (['runtime_contributions' => $this->admission->assertRuntimeContributions(...),
            'model_route' => $this->admission->assertModelRoute(...)] as $key => $check) {
            try {
                $check();
            } catch (ResourceOperationException $exception) {
                $refusals[$key] = $exception;
                break;
            }
        }

        return $refusals;
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
