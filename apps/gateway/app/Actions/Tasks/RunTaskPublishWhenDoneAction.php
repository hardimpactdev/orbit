<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskDonePublication;
use App\Domain\Tasks\TaskGroupDone;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskPrMode;
use App\Domain\Tasks\TaskPrModeChoice;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Support\Str;
use Throwable;

/**
 * One tick of `task:pr-when-done` (docs/cli/task/pr-when-done.mmd): each task copy group in `pr_when_done` whose work is done, and whose
 * done check recorded `pr_when_done`, gets the publish of that check through the same admission as `task:publish`. A check whose publish
 * failed or was cancelled, by any caller, is not started again. A refusal at admission is kept on the group; only a busy group or a turn
 * that may still run is a quiet skip.
 *
 * @phpstan-type Result array{group: int, check: int|null, result: string, code: string|null, operation: int|null}
 */
final readonly class RunTaskPublishWhenDoneAction
{
    public const string Caller = 'gateway:pr-when-done';

    /** Codes that say "not now" rather than "refused": the next tick reads the group again. */
    private const array QuietCodes = ['operation.target_busy', 'tasks.copy_turn_unended'];

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
        // A publish of this check that failed or was cancelled, whoever started it, is not started again by the tick.
        $failed = TaskDonePublication::unsuccessful($group, $check);
        if ($failed !== null) {
            return self::result($group, $check->id, 'skipped', TaskDonePublication::code($failed), $failed->id);
        }
        $key = "pr-when-done:{$group->id}:{$check->id}";
        try {
            $operation = $this->idempotency->under('tasks.publish', $key, hash('sha256', "tasks.publish|{$group->id}|{$check->id}"),
                fn () => $this->publish->execute($group, TaskDonePublication::for($group, $check), self::Caller, (string) Str::uuid(), doneCheck: $check->id));
        } catch (ResourceOperationException $exception) {
            if (! in_array($exception->errorCode, self::QuietCodes, true)) {
                Task::topLevel()->whereKey($group->id)->update(['publish_refusal' => json_encode(['code' => $exception->errorCode, 'check_id' => $check->id, 'at' => now()->toIso8601String()], JSON_THROW_ON_ERROR)]);
            }

            return self::result($group, $check->id, 'skipped', $exception->errorCode);
        }

        return self::result($group, $check->id, $operation->wasRecentlyCreated ? 'admitted' : 'replayed', null, $operation->id);
    }

    /** @return Result */
    private static function result(Task $group, ?int $check, string $result, ?string $code, ?int $operation = null): array
    {
        return ['group' => $group->id, 'check' => $check, 'result' => $result, 'code' => $code, 'operation' => $operation];
    }
}
