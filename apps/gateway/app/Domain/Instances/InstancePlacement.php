<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskRuntime;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use LogicException;

/**
 * The one placement rule that every writer of an Instance or its runtime passes before it writes anything
 * (docs/reference/projects.md#nested-fleet-setting). It says whether a Project may get a new Instance and, for a container,
 * in which Incus project it lives. The runtime records that project once (InstanceContainer::reserve); every later step reads
 * the record.
 *
 * Each writer calls the entry for its kind: `assert()` where the result can be a container, `checkout()` where it is always a
 * host checkout, `taskWorkspace()` for a task workspace on a task VM, `taskRuntime()` where a task starts. A nested-fleet Project's Instances are only containers in the nested
 * project, made from the nested-fleet image (docs/reference/development-containers.md#the-nested-fleet-image): `assert()` refuses
 * it early on a Node whose switch is off, `place()` decides once the result is known, and the other two entries refuse it.
 */
final readonly class InstancePlacement
{
    /** The Incus project of a nested-fleet Project's containers: it allows nesting (docs/reference/development-containers.md#nested-fleet). */
    public const string NestedProject = 'orbit-instances-nested';

    /**
     * The early check of a writer whose result can be a container, before it writes anything: development create and its admission.
     * A nested-fleet Project needs a Node whose switch is on; `place()` decides the rest once the result is known. A missing Node is
     * the writer's own not-found.
     */
    public static function assert(Project $project, ?Node $node): void
    {
        if ($project->nested_fleet && $node instanceof Node) {
            self::assertSwitchedOn($node);
        }
    }

    /** A writer whose result is always a host checkout: registration and the production clone. */
    public static function checkout(Project $project): void
    {
        if ($project->nested_fleet) {
            throw self::unavailable();
        }
    }

    /**
     * Where a new task of the Project starts: a copy of its default Instance for a nested-fleet Project or a Project whose default is a
     * container, a task VM for a Project whose default is a host checkout (docs/reference/development-containers.md#tasks-in-an-instance-container).
     * The one place that decides it for a start. Later work follows the group's own workspace: a group that owns a copy works in it.
     */
    public static function taskRuntime(Project $project): TaskRuntime
    {
        return $project->nested_fleet || Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->whereHas('runtime')->exists()
            ? TaskRuntime::Copy : TaskRuntime::Vm;
    }

    /**
     * A writer of a task workspace on a task VM: task provision and admission, and a proof's workspace. No task VM can hold a nested-fleet
     * Project's work. Every other Project keeps its VM path here, whatever its default is.
     */
    public static function taskWorkspace(Project $project): void
    {
        if ($project->nested_fleet) {
            throw self::unavailable();
        }
    }

    /**
     * Where a new Instance of the Project lives on the Node: the Incus project of its container, or null for a host checkout. A
     * nested-fleet Project lives only in a container in the nested project, on a Node whose nested-fleet switch is on.
     */
    public static function place(Project $project, Node $node, bool $container): ?string
    {
        if (! $project->nested_fleet) {
            return $container ? InstanceHostIntent::Project : null;
        }
        self::assertSwitchedOn($node);
        if (! $container || ! app(ContainerNodes::class)->includes($node)) {
            throw new ResourceOperationException('instance.nested_fleet_container_required',
                'An Instance of a nested-fleet Project must be a container: the Node must be on the build list and the default must be a container.', 409);
        }

        return self::NestedProject;
    }

    /**
     * Whether a container runtime holds a nested fleet that Orbit keeps: a runtime in the nested project, of a nested-fleet Project, on a
     * Node whose switch is on. Only then does its intent carry `fleet` (NestedFleetIntent).
     */
    public static function keepsFleet(Project $project, Node $node, string $incusProject): bool
    {
        return $incusProject === self::NestedProject && $project->nested_fleet
            && app(NodeSettingsNormalizer::class)->fromStored($node->settings)?->nested_fleet === true;
    }

    /** @return list<string> Every Incus project that holds Instance containers. */
    public static function projects(): array
    {
        return [InstanceHostIntent::Project, self::NestedProject];
    }

    /**
     * The extra container keys of a recorded Incus project. A nested-fleet container runs an inner Incus whose own range must fit in
     * its isolated map, of the size its runtime records (docs/reference/development-containers.md#the-id-map-size). It never
     * starts at host boot, so only an Orbit start, behind the start guard, starts it. The start guard admits exactly these keys
     * with the common limits: it reads them from the intent's `config` (docs/reference/development-containers.md#the-container).
     *
     * @return array<string, string>
     */
    public static function config(string $incusProject, ?int $idmapSize): array
    {
        return $incusProject === self::NestedProject ? [
            'security.nesting' => 'true',
            'security.syscalls.intercept.mknod' => 'true',
            'security.syscalls.intercept.setxattr' => 'true',
            'security.idmap.isolated' => 'true',
            'security.idmap.size' => (string) ($idmapSize ?? throw new LogicException('A nested-fleet runtime records its ID map size.')),
            'limits.memory' => '12GiB',
            'boot.autostart' => 'false',
        ] : [];
    }

    private static function assertSwitchedOn(Node $node): void
    {
        if (app(NodeSettingsNormalizer::class)->fromStored($node->settings)?->nested_fleet !== true) {
            throw new ResourceOperationException('instance.nested_fleet_host_unavailable', 'The Node has not switched on nested-fleet hosting.', 409);
        }
    }

    private static function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('operation.kind_unavailable', 'Only instance:create makes Instances of a nested-fleet Project, as containers.', 503);
    }
}
