<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Instances\StartInstanceDestroyAction;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Operations\OperationStore;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Instances\ContainerWorkspaceRead;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * The cancel of a task group that owns a copy (docs/cli/task/cancel.mmd). It reads what the copy holds that origin does not, records
 * the cancel with that read and admits the normal `instance.destroy` of the copy with force. It does not wait for
 * the work: the group is cancelled when the destroy succeeds (TaskCopyLifecycle::destroyed), and a failed destroy leaves the group
 * running and bound, so the next `task:cancel` admits a new destroy.
 */
final readonly class TaskCopyEnd
{
    public function __construct(private OperationStore $store, private OperationIdempotency $idempotency, private ContainerWorkspaceRead $read) {}

    /**
     * What the copy's workspace holds beyond the seed: commits that origin lacks, uncommitted and untracked files. A stopped or unreachable
     * copy reads `unknown` with its reason. One bounded SSH read; call it outside any transaction.
     *
     * @return array<string, mixed>
     */
    public function workspace(Instance $copy): array
    {
        $read = $this->read->read($copy);
        $count = static fn (mixed $class): ?int => is_array($class) && is_int($class['count'] ?? null) ? $class['count'] : null;

        return ['state' => $read['state'], 'reason' => $read['reason'], 'read_at' => $read['read_at'], 'head' => $read['head'],
            'unpushed' => $read['unpushed'], 'uncommitted' => $read['uncommitted'], 'modified' => $count($read['modified']),
            'untracked' => $count($read['untracked']), 'stashes' => $count($read['stashes'])];
    }

    /** Refuses while an Operation holds the copy: its create still runs, or an earlier destroy has not ended. Call before any change. */
    public static function assertFree(Instance $copy): void
    {
        $held = Operation::query()->where('target_type', 'instance')->where('target_id', $copy->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->value('id');
        if ($held !== null) {
            throw new ResourceOperationException('operation.target_busy', 'An Operation still holds the task copy; cancel again when it has ended.', 409,
                details: ['operation_id' => $held]);
        }
    }

    /**
     * Records the cancel and admits the destroy of the copy, in one transaction. The record comes first and takes the group's lock key,
     * so an Operation that holds or takes the group lock refuses the record and the transaction admits no destroy. Refused while an
     * Operation holds the copy: its create still runs, or an earlier destroy has not ended.
     *
     * @param  array<string, mixed>  $workspace
     */
    public function cancel(Task $group, Instance $copy, array $workspace, string $caller, ?string $request): Operation
    {
        self::assertFree($copy);
        $key = $this->idempotency->key;
        $admit = static fn (): Operation => app(StartInstanceDestroyAction::class)->execute($copy->refresh(), true, $caller, $request);
        $input = ['group_id' => $group->id, 'instance_id' => $copy->id, 'task_vm_id' => null];

        return DB::transaction(function () use ($group, $copy, $workspace, $caller, $request, $key, $admit, $input): Operation {
            app(DirectOperation::class)->writer('tasks.cancel');
            $cancel = $this->store->create('tasks.cancel', 'task_group', $group->id, $copy->node_id, $input, $caller, $request,
                OperationStore::lockKey('task_group', $group->id));
            $destroy = $key === null ? $admit() : $this->idempotency->under('instance.destroy', $key, (string) $this->idempotency->fingerprint, $admit);
            $this->store->change($cancel, ['status' => OperationStatus::Succeeded, 'input' => [...$input, 'destroy_operation_id' => $destroy->id],
                'result' => ['group_id' => $group->id, 'instance_id' => $copy->id, 'destroy_operation_id' => $destroy->id, 'workspace' => $workspace],
                'started_at' => now(), 'finished_at' => now(), 'lock_key' => null]);

            return $cancel;
        });
    }
}
