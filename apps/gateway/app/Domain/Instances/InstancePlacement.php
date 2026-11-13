<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Project;

/**
 * The one placement rule that every writer of an Instance or its runtime passes before it writes anything
 * (docs/reference/projects.md#nested-fleet-setting). It says whether a Project may get a new Instance and, for a container,
 * in which Incus project it lives. The runtime records that project once (InstanceContainer::reserve); every later step reads
 * the record.
 *
 * Each writer calls the entry for its kind: `assert()` where the result can be a container, `checkout()` where it is always a
 * host checkout, `taskWorkspace()` for a task workspace. The image for nested-fleet containers does not exist yet, so every
 * entry still refuses a nested-fleet Project; the image change edits only the bodies here, never a caller.
 */
final readonly class InstancePlacement
{
    /** The Incus project of a nested-fleet Project's containers: it allows nesting (docs/reference/development-containers.md#nested-fleet). */
    public const string NestedProject = 'orbit-instances-nested';

    /** A writer whose result can be a container: development create and its admission, and the runtime reservation. */
    public static function assert(Project $project): void
    {
        if ($project->nested_fleet) {
            throw self::unavailable();
        }
    }

    /** A writer whose result is always a host checkout: registration and the production clone. */
    public static function checkout(Project $project): void
    {
        if ($project->nested_fleet) {
            throw self::unavailable();
        }
    }

    /** A writer of a task workspace: task provision and admission, and a proof's workspace. */
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
        if (app(NodeSettingsNormalizer::class)->fromStored($node->settings)?->nested_fleet !== true) {
            throw new ResourceOperationException('instance.nested_fleet_host_unavailable', 'The Node has not switched on nested-fleet hosting.', 409);
        }
        if (! $container || ! app(ContainerNodes::class)->includes($node)) {
            throw new ResourceOperationException('instance.nested_fleet_container_required',
                'An Instance of a nested-fleet Project must be a container: the Node must be on the build list and the default must be a container.', 409);
        }

        return self::NestedProject;
    }

    /** @return list<string> Every Incus project that holds Instance containers. */
    public static function projects(): array
    {
        return [InstanceHostIntent::Project, self::NestedProject];
    }

    /**
     * The extra container keys of a recorded Incus project. A nested-fleet container runs an inner Incus whose own range of
     * 1,000,000 reserved and 100,000,000 subordinate IDs must fit in its isolated map, the size of a task container place.
     *
     * @return array<string, string>
     */
    public static function config(string $incusProject): array
    {
        return $incusProject === self::NestedProject ? [
            'security.nesting' => 'true',
            'security.syscalls.intercept.mknod' => 'true',
            'security.syscalls.intercept.setxattr' => 'true',
            'security.idmap.isolated' => 'true',
            'security.idmap.size' => (string) InstanceHostIntent::TaskPlaceIds,
            'limits.memory' => '12GiB',
        ] : [];
    }

    private static function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('operation.kind_unavailable', 'Instances of a nested-fleet Project are not available until nested-fleet hosting is built.', 503);
    }
}
