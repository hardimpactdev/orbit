<?php

declare(strict_types=1);

namespace App\Domain\Proof;

use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskOperations;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;

/**
 * Admits `orbit proof COMMAND`: the cases of one command run on the task VM that calls, with the nested fleet it already has and no
 * new VM. The Gateway records the intent; the Swamp workflow `proof-fleet-verify` runs it, restores `base` first and leaves a clean
 * fleet after. See docs/cli/proof/proof.mmd.
 */
final readonly class ProofVerifyIntent
{
    public function __construct(private TaskOperations $operations, private ProofSettings $settings) {}

    /** Resolve nested commands exactly as CI does. */
    public static function casesFile(string $command): string
    {
        return ProofSelection::fileOf($command);
    }

    /**
     * The route admits only a Node with Gateway access, so a named group comes from such a Node (an operator machine, the Swamp role).
     * Without one, the group is the calling task VM's own.
     */
    public function start(Node $caller, string $command, ?int $groupId, string $triggeredBy, ?string $requestId): Operation
    {
        if ($groupId !== null) {
            $group = Task::topLevel()->find($groupId)
                ?? throw new ResourceOperationException('tasks.group_missing', "Task group [{$groupId}] does not exist.", 404);
        } else {
            $group = TaskVm::forNode($caller)?->group;
        }
        if (! $group instanceof Task) {
            throw new ResourceOperationException('proof.no_task_vm', 'The proof runs on the task VM that calls it. This Node is not a task VM.', 409);
        }

        // The caller may be the agent of a running turn, which holds the group and waits for this proof, so the turn does not count as busy.
        return $this->operations->start($group, OperationKinds::ProofVerify, [], static function (): void {}, $triggeredBy, $requestId,
            fn (Task $group): array => $this->capture($group, $command), [OperationKinds::TasksTurn]);
    }

    /** @return array<string,mixed> */
    private function capture(Task $group, string $command): array
    {
        $group->load('taskable');
        $instance = $group->taskable;
        if ($group->project_id !== $this->settings->projectId()) {
            throw new ResourceOperationException('proof.wrong_project', 'Proof cases exist only for the Orbit repository.', 409);
        }
        if (! $instance instanceof Instance || $instance->task_workspace_owner_id !== $group->id || $instance->project_id !== $group->project_id
            || TaskVm::forGroup($group)?->node_id !== $instance->node_id || $instance->checkout_path === '') {
            throw new ResourceOperationException('tasks.no_workspace', 'The group requires its owned task VM workspace.', 409);
        }

        return [
            'group_id' => $group->id, 'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'command' => $command, 'file' => self::casesFile($command),
            'checkout_path' => $instance->checkout_path, 'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($instance->node),
            // The author's fleet may have moved since the last proof: restore `base` before the first batch, and leave it clean after the last.
            'restore_first' => true, 'final_restore' => true,
        ];
    }
}
