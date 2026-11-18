<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Instances\InstanceContainer;
use App\Domain\Instances\NestedFleetIntent;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskInstanceWorkspace;
use App\Domain\Tasks\TaskOperations;
use App\Domain\Tasks\TaskTurn;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;

/**
 * Admits `orbit proof COMMAND`: the cases of one command run in the named group's task copy, with the nested fleet it already has.
 * The Gateway records the intent; the Swamp workflow `proof-fleet-verify` runs it. See docs/cli/proof/proof.mmd.
 */
final readonly class ProofVerifyIntent
{
    public function __construct(private TaskOperations $operations) {}

    /** Resolve nested commands exactly as CI does. */
    public static function casesFile(string $command): string
    {
        return ProofSelection::fileOf($command);
    }

    /**
     * The route admits only a Node with Gateway access, so the named group comes from such a Node (an operator machine, the Swamp role).
     * Task VMs are removed, so no calling Node stands for a group: a request without a group is refused before it reads anything.
     */
    public function start(Node $caller, string $command, ?int $groupId, string $triggeredBy, ?string $requestId): Operation
    {
        if ($groupId === null) {
            throw new ResourceOperationException('proof.group_required', 'Name the task group whose copy runs the proof: orbit proof COMMAND --group=<id>.', 422);
        }
        $group = Task::topLevel()->find($groupId)
            ?? throw new ResourceOperationException('tasks.group_missing', "Task group [{$groupId}] does not exist.", 404);

        return $this->operations->start($group, OperationKinds::ProofVerify, [], static function (): void {}, $triggeredBy, $requestId,
            fn (Task $group): array => $this->capture($group, $command));
    }

    /** @return array<string,mixed> */
    private function capture(Task $group, string $command): array
    {
        $group->load('taskable', 'project');
        $copy = TaskInstanceWorkspace::forGroup($group)
            ?? throw new ResourceOperationException('tasks.no_workspace', 'The group has no task copy.', 409);

        return $this->captureCopy($group, $copy, $command);
    }

    /**
     * A task copy: Swamp reaches the runner through the copy's Node, `incus exec` and `runuser -u agent` (docs/cli/proof/proof.mdx#the-run).
     * A batch starts with the reset of the copy's inner fleet (`restore_first`, with `container` and `fleet` for the play); only a batch
     * after a read-only batch skips it ({@see ProofCopies::input}). No restore follows the last: the next proof resets first. Only a clone that keeps its fleet has one to reset;
     * a Project's default Instance, whose fleet is the seed of every copy, is refused.
     *
     * @return array<string,mixed>
     */
    public function captureCopy(Task $group, TaskInstanceWorkspace $copy, string $command): array
    {
        if (! $group->project->nested_fleet) {
            throw new ResourceOperationException('proof.wrong_project', 'Proof cases in a task copy need a Project with a nested fleet.', 409);
        }
        $copy->requireReady();
        TaskTurn::refuseUnended($group);
        $target = $copy->intent();
        // Both names stand before the `--` of `incus exec`, which Swamp runs as root on the Node: neither may read as an option.
        foreach ($target['runtime'] as $name) {
            if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9_-]{0,62}\z/D', $name) !== 1) {
                throw new ResourceOperationException('tasks.no_workspace', 'The task copy has no valid recorded container.', 409);
            }
        }
        $fleet = app(NestedFleetIntent::class)->forClone($copy->instance, $copy->runtime)
            ?? throw new ResourceOperationException('proof.copy_fleet_missing', 'The task copy is not a clone that keeps its nested fleet, so the proof has no fleet to reset.', 409);

        return [
            'group_id' => $group->id, 'instance_id' => $copy->instance->id, 'node_id' => $copy->instance->node_id, 'command' => $command, 'file' => self::casesFile($command),
            'checkout_path' => TaskInstanceWorkspace::Root, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($copy->instance->node),
            'copy' => $target['runtime'], 'restore_first' => true, 'final_restore' => false,
            'container' => app(InstanceContainer::class)->intent($copy->instance, $copy->runtime), 'fleet' => $fleet,
        ];
    }
}
