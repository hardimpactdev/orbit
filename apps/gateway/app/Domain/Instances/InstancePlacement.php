<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Proof\ProofCopyHosts;
use App\Domain\Proof\ProofSettings;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskVmRemoved;
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
 * host checkout, `taskCopy()` where a task starts. A nested-fleet Project's Instances are only containers in the nested
 * project, made from the nested-fleet image (docs/reference/development-containers.md#the-nested-fleet-image): `assert()` refuses
 * it early on a Node that hosts no nested fleets, `place()` decides once the result is known, and `checkout()` refuses it.
 */
final readonly class InstancePlacement
{
    /** The Incus project of a nested-fleet Project's containers: it allows nesting (docs/reference/development-containers.md#nested-fleet). */
    public const string NestedProject = 'orbit-instances-nested';

    /** The memory of a nested-fleet container, and its swap: Incus gives a container with `limits.memory` no swap unless the swap is a size. */
    private const string NestedMemory = '12GiB';

    /**
     * The early check of a writer whose result can be a container, before it writes anything: development create and its admission.
     * A CI-only Node (ProofCopyHosts::ciOnly) takes no Instance of this writer but a proof copy, first: a task group's copy of the proof
     * Project's default (`$taskCopy`; docs/reference/node-provisioning.md#proof-copies-on-a-ci-node). A nested-fleet Project needs a Node
     * that hosts nested fleets; `place()` decides the rest once the result is known. A missing Node is the writer's own not-found.
     */
    public static function assert(Project $project, ?Node $node, bool $taskCopy = false): void
    {
        if ($node instanceof Node && ProofCopyHosts::ciOnly($node) && ! ($taskCopy && $project->id === app(ProofSettings::class)->projectId())) {
            throw new ResourceOperationException('instance.node_ci_only', 'The Node is a CI-only Node: only a proof copy is made there.', 409);
        }
        if ($project->nested_fleet && $node instanceof Node) {
            self::assertHostsNestedFleets($node);
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
     * Whether a new task of the Project starts as a copy of its default Instance: a nested-fleet Project, or a Project whose default is a
     * container (docs/reference/development-containers.md#tasks-in-an-instance-container). Later work follows the group's own workspace.
     */
    public static function copiesTasks(Project $project): bool
    {
        return $project->nested_fleet || Instance::query()->where('project_id', $project->id)->where('name', DefaultInstanceName::Name)->whereHas('runtime')->exists();
    }

    /** Where a task starts: only as a copy. A Project whose default is no container gets the refusal of the removed task VM runtime. */
    public static function taskCopy(Project $project): void
    {
        if (! self::copiesTasks($project)) {
            throw TaskVmRemoved::refusal();
        }
    }

    /**
     * Where a new Instance of the Project lives on the Node: the Incus project of its container, or null for a host checkout. A
     * nested-fleet Project lives only in a container in the nested project, on a Node that hosts nested fleets.
     */
    public static function place(Project $project, Node $node, bool $container): ?string
    {
        if (! $project->nested_fleet) {
            return $container ? InstanceHostIntent::Project : null;
        }
        self::assertHostsNestedFleets($node);
        // A CI-only Node hosts containers without the build list: assert() let only a proof copy through to it.
        if (! $container || ! (app(ContainerNodes::class)->includes($node) || ProofCopyHosts::ciOnly($node))) {
            throw new ResourceOperationException('instance.nested_fleet_container_required',
                'An Instance of a nested-fleet Project must be a container: the Node must be on the build list and the default must be a container.', 409);
        }

        return self::NestedProject;
    }

    /**
     * Whether a container runtime holds a nested fleet that Orbit keeps: a runtime in the nested project, of a nested-fleet Project, on a
     * Node that hosts nested fleets. Only then does its intent carry `fleet` (NestedFleetIntent).
     */
    public static function keepsFleet(Project $project, Node $node, string $incusProject): bool
    {
        return $incusProject === self::NestedProject && $project->nested_fleet && self::hostsNestedFleets($node);
    }

    /**
     * Whether the Node hosts nested fleets (docs/reference/projects.md#nested-fleet-setting): its nested-fleet switch is on, or it is a
     * CI-only Node whose last `ci-runner` converge prepared nested hosting (`ci_nested_hosting`, ProofCopyHosts::recordConverge). A CI
     * Node gets no switch: its own converge prepares the hosting, and assert() lets only a proof copy through to it.
     */
    public static function hostsNestedFleets(Node $node): bool
    {
        return app(NodeSettingsNormalizer::class)->fromStored($node->settings)?->nested_fleet === true
            || ($node->ci_nested_hosting === true && ProofCopyHosts::ciOnly($node));
    }

    /** @return list<string> Every Incus project that holds Instance containers. */
    public static function projects(): array
    {
        return [InstanceHostIntent::Project, self::NestedProject];
    }

    /**
     * The extra container keys of a recorded Incus project. A nested-fleet container runs an inner Incus whose own range must fit in
     * its isolated map, of the size its runtime records (docs/reference/development-containers.md#the-id-map-size). It never
     * starts at host boot, so only an Orbit start, behind the start guard, starts it. Its swap is a byte size equal to its memory, so
     * its nested fleet sleeps into swap (docs/reference/development-containers.md#sleep-and-wake). The start guard admits exactly these keys
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
            'limits.memory' => self::NestedMemory,
            'limits.memory.swap' => self::NestedMemory,
            'boot.autostart' => 'false',
        ] : [];
    }

    private static function assertHostsNestedFleets(Node $node): void
    {
        if (! self::hostsNestedFleets($node)) {
            throw new ResourceOperationException('instance.nested_fleet_host_unavailable',
                'The Node hosts no nested fleets: switch on its nested-fleet hosting, or converge the ci-runner role of a CI-only Node.', 409);
        }
    }

    private static function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('operation.kind_unavailable', 'Only instance:create makes Instances of a nested-fleet Project, as containers.', 503);
    }
}
