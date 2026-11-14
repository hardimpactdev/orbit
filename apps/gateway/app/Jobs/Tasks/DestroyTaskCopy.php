<?php

declare(strict_types=1);

namespace App\Jobs\Tasks;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Gateway\GatewayQueue;
use App\Domain\Instances\InstanceLifecycleContributions;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCopyEnd;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Destroys the copy of a task group whose create failed (docs/reference/tasks.md#removal-by-orbit), after the create's projection
 * committed: the one removal of a copy that Orbit starts by itself. It admits the normal `instance.destroy` with force. A copy of a group
 * that still runs, or one that an Operation still holds, is left alone. A copy that the records do not prove unused is read first, as
 * `task:cancel` reads it; one that holds work origin lacks, or that cannot be read, stays, and the group asks for assistance. When the
 * destroy fails, the failed group keeps owning the copy; `orbit instance:destroy <id> --yes --force` is the retry.
 */
final class DestroyTaskCopy implements ShouldQueue
{
    use Queueable;

    /** Opens the reason of the assistance request of a copy that this job kept. */
    public const string KeptReason = 'Orbit kept the task copy after its failed create: ';

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(public int $instanceId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Tasks->value)->afterCommit();
    }

    public function handle(StartInstanceDestroyAction $destroy, InstanceLifecycleContributions $contributions, TaskCopyEnd $end): void
    {
        [$instance, $group] = $this->destroyable() ?? [null, null];
        if ($instance === null || $group === null) {
            return;
        }
        if (! self::neverUsable($instance, $contributions)) {
            // One bounded SSH read, outside any transaction; the guards are read again after it.
            $workspace = $end->workspace($group, $instance);
            try {
                TaskCopyEnd::assertDiscardable($workspace, false);
            } catch (ResourceOperationException $refusal) {
                TaskAssistance::apply($group, AssistanceKind::RemovalFailure, reason: self::keptReason($group, $workspace), replaceFailure: true);

                return;
            }
            [$instance] = $this->destroyable() ?? [null];
            if ($instance === null) {
                return;
            }
        }
        $destroy->execute($instance, true, 'tasks');
    }

    /**
     * Whether the records prove that no agent could have worked in the copy: its create did not succeed and no T3 server is registered
     * for it. A create that succeeded registered T3, so its thread was usable.
     */
    public static function neverUsable(Instance $copy, InstanceLifecycleContributions $contributions): bool
    {
        $create = Operation::query()->where('kind', 'instance.create')->where('target_type', 'instance')->where('target_id', $copy->id)->orderByDesc('id')->first();

        return $create?->status !== OperationStatus::Succeeded && ! $contributions->registered($copy);
    }

    /** @return array{Instance, Task}|null The copy and its group, when the group no longer runs and no Operation holds the copy. */
    private function destroyable(): ?array
    {
        $instance = Instance::query()->find($this->instanceId);
        if ($instance === null || $instance->task_workspace_owner_id === null || ! $instance->runtime()->exists()
            || Operation::query()->where('target_type', 'instance')->where('target_id', $instance->id)->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            return null;
        }
        $group = Task::topLevel()->find($instance->task_workspace_owner_id);

        return $group === null || in_array($group->status, TaskGroupStatus::active(), true) ? null : [$instance, $group];
    }

    /** @param  array<string, mixed>  $workspace */
    private static function keptReason(Task $group, array $workspace): string
    {
        $count = static fn (string $key): string => is_int($workspace[$key] ?? null) ? (string) $workspace[$key] : 'unknown';
        $held = $workspace['state'] === 'dirty'
            ? "it holds work that origin lacks: {$count('unpushed')} unpushed commits, {$count('uncommitted')} uncommitted files, {$count('untracked')} of them untracked."
            : 'its workspace could not be read: '.(is_string($workspace['reason'] ?? null) ? $workspace['reason'] : 'unknown').'.';

        return self::KeptReason.$held." End it with `orbit task:cancel {$group->id}`, with `--discard` if the work is not wanted.";
    }
}
