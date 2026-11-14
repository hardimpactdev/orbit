<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskDonePublication;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskPrModeChoice;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * One tick of `task:pr-when-done` (docs/cli/task/pr-when-done.mmd): each task copy group in `pr_when_done` whose work is done, and whose
 * done check recorded `pr_when_done`, gets the publish of that check through the same admission as `task:publish`. A check whose publish
 * failed or was cancelled, by any caller, is not started again, unless it timed out on a Swamp behavior lock and attempts remain: the
 * retry is keyed with its attempt. A refusal at admission is kept on the group; only a busy group or a turn that may still run is a quiet
 * skip.
 *
 * @phpstan-type Result array{group: int, check: int|null, result: string, code: string|null, operation: int|null}
 */
final readonly class RunTaskPublishWhenDoneAction
{
    public const string Caller = 'gateway:pr-when-done';

    /**
     * Codes that say "not now" rather than "refused": the next tick reads the group again. `tasks.not_done` is the re-check under the
     * row lock; it must not stick once, say, the subtask added in between is cancelled.
     */
    private const array QuietCodes = ['operation.target_busy', 'tasks.copy_turn_unended', 'tasks.not_done'];

    /** The code a fault that is no refusal leaves on the group, so the publication shows it instead of `publishing`. */
    public const string FaultCode = 'tasks.publish_failed';

    public function __construct(private StartTaskPublishAction $publish, private TaskGroupDone $done, private OperationIdempotency $idempotency) {}

    /** @return list<Result> */
    public function execute(): array
    {
        $results = [];
        $groups = Task::topLevel()->with('project')->where('orchestration', TaskOrchestration::External->value)->whereNull('pr_url')
            ->where('taskable_type', (new Instance)->getMorphClass())
            ->whereNotIn('status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses()))->orderBy('id')->get();
        foreach ($groups as $group) {
            // One group's fault is reported and never stops the tick for the groups after it.
            try {
                $result = $this->check($group);
            } catch (Throwable $exception) {
                report($exception);
                $result = self::result($group, null, 'failed', null);
            }
            if ($result !== null) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /** @return Result|null null for a group whose work is not done */
    private function check(Task $group): ?array
    {
        $check = $this->done->check($group);
        if ($check === null) {
            return null;
        }
        try {
            $mode = TaskPrModeChoice::of($group)->mode;
        } catch (ResourceOperationException $exception) {
            return self::result($group, $check->id, 'skipped', $exception->errorCode);
        }
        if ($mode !== TaskPrMode::PrWhenDone || $check->pr_mode !== TaskPrMode::PrWhenDone) {
            return self::result($group, $check->id, 'waiting', null);
        }
        if (($group->publish_refusal['check_id'] ?? null) === $check->id) {
            return self::result($group, $check->id, 'skipped', $group->publish_refusal['code'] ?? null);
        }
        // A publish of this check that failed or was cancelled, whoever started it, is not started again by the tick; one that timed out
        // on a lock is, with attempts left, under the key of the attempt. An open retry replays under its key; admission refuses any other
        // open publish of the group as busy.
        $failed = TaskDonePublication::unsuccessful($group, $check, $mode);
        if ($failed !== null) {
            return self::result($group, $check->id, 'skipped', TaskDonePublication::code($failed), $failed->id);
        }
        $attempt = TaskDonePublication::attempt($group, $check);
        $suffix = $attempt === 1 ? '' : ':'.$attempt;
        $before = self::lastPublish($group);
        try {
            $operation = $this->idempotency->under('tasks.publish', "pr-when-done:{$group->id}:{$check->id}{$suffix}", hash('sha256', "tasks.publish|{$group->id}|{$check->id}".($attempt === 1 ? '' : '|'.$attempt)),
                fn () => $this->publish->execute($group, TaskDonePublication::for($group, $check), self::Caller, (string) Str::uuid(), doneCheck: $check->id, byItself: true));
        } catch (ResourceOperationException $exception) {
            if (! in_array($exception->errorCode, self::QuietCodes, true)) {
                self::refuse($group, $check->id, $exception->errorCode, $before);
            }

            return self::result($group, $check->id, 'skipped', $exception->errorCode);
        } catch (Throwable $exception) {
            report($exception);
            self::refuse($group, $check->id, self::FaultCode, $before);

            return self::result($group, $check->id, 'failed', self::FaultCode);
        }

        return self::result($group, $check->id, $operation->wasRecentlyCreated ? 'admitted' : 'replayed', null, $operation->id);
    }

    /** The newest publish of the group, before this admission: a newer one means a publish was admitted in between. */
    private static function lastPublish(Task $group): int
    {
        $last = Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)
            ->where('target_id', $group->id)->orderByDesc('id')->value('id');

        return is_int($last) ? $last : 0;
    }

    /** Stores the refusal under the group's row lock, unless a publish of the group was admitted since the tick read: its code would be stale. */
    private static function refuse(Task $group, int $check, string $code, int $before): void
    {
        DB::transaction(static function () use ($group, $check, $code, $before): void {
            Task::topLevel()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            if (self::lastPublish($group) > $before) {
                return;
            }
            Task::topLevel()->whereKey($group->id)->update(['publish_refusal' => json_encode(['code' => $code, 'check_id' => $check, 'at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)]);
        });
    }

    /** @return Result */
    private static function result(Task $group, ?int $check, string $result, ?string $code, ?int $operation = null): array
    {
        return ['group' => $group->id, 'check' => $check, 'result' => $result, 'code' => $code, 'operation' => $operation];
    }
}
