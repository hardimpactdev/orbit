<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\StartTaskCheckData;
use App\Data\Tasks\StartTaskPublishData;
use App\Data\Tasks\TaskTurnData;
use App\Data\Tasks\UpdateTaskData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCopyTurn;
use App\Domain\Tasks\TaskDonePublication;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskOrchestration;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Support\Str;
use Throwable;

/**
 * One tick of `task:merge-round` (docs/reference/tasks.md#the-merge-round): each open task copy group whose newest publish P failed with
 * `tasks.publish_conflict` gets the next step of its merge round, read from the group's Operations after P alone: sync, implementer turn,
 * handoff check, reviewer turn, publish. Each step is admitted through the action of its command and keyed `merge-round:<group>:<P>:<step>`.
 * A step that failed, asked or was refused stops the round with one assistance request; an Operation of anyone else after P makes it yield.
 *
 * @phpstan-type Result array{group: int, publish: int, step: string|null, result: string, code: string|null, operation: int|null}
 */
final readonly class RunTaskMergeRoundAction
{
    public const string Caller = 'gateway:merge-round';

    public const string ConflictCode = 'tasks.publish_conflict';

    /** Codes that say "not now": the next tick reads the group again. */
    private const array QuietCodes = ['operation.target_busy', 'tasks.copy_turn_unended'];

    public function __construct(
        private StartTaskSyncAction $sync,
        private StartTaskTurnAction $turn,
        private StartTaskCheckAction $check,
        private StartTaskPublishAction $publish,
        private UpdateTaskAction $update,
        private OperationIdempotency $idempotency,
    ) {}

    /** @return list<Result> */
    public function execute(): array
    {
        $results = [];
        $groups = Task::topLevel()->with('project')->where('orchestration', TaskOrchestration::External->value)->where('assistance_requested', false)
            ->where('taskable_type', (new Instance)->getMorphClass())
            ->whereNotIn('status', array_map(static fn (TaskGroupStatus $status): string => $status->value, TaskGroupStatus::endedStatuses()))->orderBy('id')->get();
        foreach ($groups as $group) {
            $publish = Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)
                ->where('target_id', $group->id)->orderByDesc('id')->first();
            if (! $publish instanceof Operation || $publish->status !== OperationStatus::Failed || $publish->error_code !== self::ConflictCode) {
                continue;
            }
            // One group's fault is reported and never stops the tick for the groups after it.
            try {
                $results[] = $this->round($group, $publish);
            } catch (Throwable $exception) {
                report($exception);
                $results[] = self::result($group, $publish, null, 'failed', null);
            }
        }

        return $results;
    }

    /** @return Result */
    private function round(Task $group, Operation $publish): array
    {
        if (TaskCopyTurn::unsettled($group) !== [] || Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->whereNotIn('status', OperationStatus::terminalValues())->exists()) {
            return self::result($group, $publish, null, 'busy', null);
        }
        $c0 = TaskCheck::query()->findOrFail(ValidatedData::integer($publish->input['check']));
        // A conflict of a check that a round made is the round's own second conflict, never a new round.
        if (Operation::query()->where('kind', OperationKinds::TasksCheck)->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('input->check_id', $c0->id)->where('idempotency_key', 'like', 'merge-round:'.$group->id.':%')->exists()) {
            return $this->stop($group, $publish, 'publish', $publish, 'conflicted again');
        }
        $prefix = self::prefix($group, $publish);
        $last = Operation::query()->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('id', '>', $publish->id)->orderByDesc('id')->first();
        if ($last instanceof Operation && ! str_starts_with((string) $last->idempotency_key, $prefix)) {
            return self::result($group, $publish, null, 'yielded', null, $last->id);
        }
        $step = $last === null ? null : substr((string) $last->idempotency_key, strlen($prefix));
        $subtask = Task::query()->where('parent_id', $group->id)->findOrFail($c0->task_id);

        return match ($step) {
            null => $this->start($group, $publish, 'sync', OperationKinds::TasksSync, fn (): Operation => $this->sync->execute($group, self::Caller, (string) Str::uuid())),
            'sync' => $this->afterSync($group, $publish, $subtask, $last),
            'turn' => $this->afterTurn($group, $publish, $subtask, $last),
            'check' => $this->afterCheck($group, $publish, $subtask, $c0, $last),
            'review' => $this->afterReview($group, $publish, $subtask, $last),
            default => self::result($group, $publish, $step, 'ended', null, $last->id),
        };
    }

    /** @return Result */
    private function afterSync(Task $group, Operation $publish, Task $subtask, Operation $sync): array
    {
        if ($sync->status !== OperationStatus::Succeeded) {
            return $this->stop($group, $publish, 'sync', $sync, 'failed');
        }
        if ($subtask->status === TaskStatus::Completed) {
            // The reopen of `task:update --status=running` (docs/reference/tasks.md#reopen-the-last-subtask): a turn runs only on an open subtask.
            try {
                $this->update->execute($group, $subtask, new UpdateTaskData(null, null, null, status: TaskStatus::Running));
            } catch (ResourceOperationException $exception) {
                return $this->refused($group, $publish, 'turn', $exception);
            }
        }
        $prompt = self::mergePrompt($group, $publish, $sync);

        return $this->start($group, $publish, 'turn', OperationKinds::TasksCopyTurn,
            fn (): Operation => $this->turn->execute($group, new TaskTurnData($subtask->id, 'implementer', $prompt), self::Caller, (string) Str::uuid()));
    }

    /** @return Result */
    private function afterTurn(Task $group, Operation $publish, Task $subtask, Operation $turn): array
    {
        if (! self::turnDone($turn)) {
            return $this->stop($group, $publish, 'turn', $turn, self::turnEnd($turn));
        }

        return $this->start($group, $publish, 'check', OperationKinds::TasksCheck,
            fn (): Operation => $this->check->execute($group, new StartTaskCheckData($subtask->id, TaskCheckKind::Handoff), self::Caller, (string) Str::uuid()));
    }

    /** @return Result */
    private function afterCheck(Task $group, Operation $publish, Task $subtask, TaskCheck $c0, Operation $check): array
    {
        $c1 = TaskCheck::query()->find(ValidatedData::integer($check->input['check_id']));
        if ($check->status !== OperationStatus::Succeeded || ! $c1 instanceof TaskCheck || $c1->status !== TaskCheckStatus::Passed) {
            return $this->stop($group, $publish, 'check', $check, 'failed');
        }
        $prompt = self::reviewPrompt($publish, $c0, $c1);

        return $this->start($group, $publish, 'review', OperationKinds::TasksCopyTurn,
            fn (): Operation => $this->turn->execute($group, new TaskTurnData($subtask->id, 'reviewer', $prompt, check: $c1->id), self::Caller, (string) Str::uuid()));
    }

    /**
     * The reviewer's sent text on C1 is its approval (TaskCopyTurn::PrTextRule). A done-check publish P completes the subtask again, so the
     * done rule makes C1 the done check; any other P pushes C1 only.
     *
     * @return Result
     */
    private function afterReview(Task $group, Operation $publish, Task $subtask, Operation $review): array
    {
        if (! self::turnDone($review)) {
            return $this->stop($group, $publish, 'review', $review, self::turnEnd($review));
        }
        $check = Operation::query()->where('kind', OperationKinds::TasksCheck)->where('idempotency_key', self::prefix($group, $publish).'check')->sole();
        $c1 = TaskCheck::query()->findOrFail(ValidatedData::integer($check->input['check_id']));
        if ($c1->pr_text_operation_id !== $review->id || $c1->pr_title === null || $c1->pr_body === null || ($review->input['candidate'] ?? null) !== $c1->commit_sha) {
            return $this->stop($group, $publish, 'review', $review, 'sent no pull request text');
        }
        // The automatic publish may have published C1 since this tick read the group: then it is the one publish of C1.
        if (Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('input->check', $c1->id)->exists()) {
            return self::result($group, $publish, 'publish', 'yielded', null);
        }
        $done = is_array($publish->input['pull_request'] ?? null);
        if ($done && $subtask->refresh()->status !== TaskStatus::Completed) {
            try {
                $this->update->execute($group, $subtask, new UpdateTaskData(null, null, null, status: TaskStatus::Completed));
            } catch (ResourceOperationException $exception) {
                return $this->refused($group, $publish, 'publish', $exception);
            }
        }

        return $this->start($group, $publish, 'publish', OperationKinds::TasksPublish, fn (): Operation => $done
            ? $this->publish->execute($group->refresh(), TaskDonePublication::for($group, $c1), self::Caller, (string) Str::uuid(), doneCheck: $c1->id)
            : $this->publish->execute($group, new StartTaskPublishData($c1->id), self::Caller, (string) Str::uuid()));
    }

    /**
     * @param  Closure(): Operation  $admit
     * @return Result
     */
    private function start(Task $group, Operation $publish, string $step, string $kind, Closure $admit): array
    {
        $key = self::prefix($group, $publish).$step;
        try {
            $operation = $this->idempotency->under($kind, $key, hash('sha256', "{$kind}|{$key}"), $admit);
        } catch (ResourceOperationException $exception) {
            return $this->refused($group, $publish, $step, $exception);
        }

        return self::result($group, $publish, $step, $operation->wasRecentlyCreated ? 'admitted' : 'replayed', null, $operation->id);
    }

    /** @return Result */
    private function refused(Task $group, Operation $publish, string $step, ResourceOperationException $exception): array
    {
        if (in_array($exception->errorCode, self::QuietCodes, true)) {
            return self::result($group, $publish, $step, 'skipped', $exception->errorCode);
        }

        return $this->stop($group, $publish, $step, null, 'was refused with '.$exception->errorCode);
    }

    /** @return Result */
    private function stop(Task $group, Operation $publish, string $step, ?Operation $operation, string $what): array
    {
        $at = $operation === null ? '' : ", Operation {$operation->id},";
        TaskAssistance::apply($group, AssistanceKind::Failure, reason: "The merge round after publish Operation {$publish->id} stopped: the {$step} step{$at} {$what}. "
            .'Conflicting paths: '.self::paths($publish).'.');

        return self::result($group, $publish, $step, 'stopped', null, $operation?->id);
    }

    private static function turnDone(Operation $turn): bool
    {
        return $turn->status === OperationStatus::Succeeded && ($turn->result['state'] ?? null) === AgentThreadState::Done->value;
    }

    private static function turnEnd(Operation $turn): string
    {
        return ($turn->result['state'] ?? null) === AgentThreadState::AskingForInput->value ? 'asked' : 'failed';
    }

    private static function mergePrompt(Task $group, Operation $publish, Operation $sync): string
    {
        $check = ValidatedData::integer($publish->input['check']);
        $branch = is_string($sync->result['default_branch'] ?? null) ? $sync->result['default_branch'] : (string) $group->project->default_branch;
        $head = is_string($sync->result['default_commit'] ?? null) ? $sync->result['default_commit'] : 'unknown';

        return "Merge round. The publish of check {$check} (Operation {$publish->id}) failed with tasks.publish_conflict: main moved, "
            .'and these paths conflict with it: '.self::paths($publish).". The sync brought the default head {$head} into the copy as origin/{$branch}.\n\n"
            ."Merge origin/{$branch} into the task branch. Resolve each conflict at its cause: regenerate a generated file with its own tool, for example "
            .'`bin/ci-units collect --file`, instead of editing it by hand. Keep the intent of both sides. Run only the tests that cover the conflicting '
            .'paths. Commit the merge. If a conflict needs a decision, ask.';
    }

    private static function reviewPrompt(Operation $publish, TaskCheck $c0, TaskCheck $c1): string
    {
        return "Merge round. You approved check {$c0->id} at commit {$c0->commit_sha}. Its publish (Operation {$publish->id}) conflicted with main on: "
            .self::paths($publish).". The implementer merged main, and check {$c1->id} passed at commit {$c1->commit_sha}.\n\n"
            .'Review ONLY the merge\'s own change, the conflict resolution, against the original brief; nothing else of the branch. For each merge commit in '
            ."`git log --merges {$c0->commit_sha}..{$c1->commit_sha}`, run `git show --remerge-diff <merge>`: the diff between the reviewed head plus main and "
            .'the resolved tree. If you approve, send the pull request text again: keep the text you sent earlier, or update it if the merge changes what it '
            .'says. If you do not approve, send nothing and list the defects.';
    }

    /** The conflicting paths of P's result, as the publish projected them. */
    private static function paths(Operation $publish): string
    {
        $paths = ValidatedData::stringList($publish->result['conflicting_paths'] ?? []);
        $more = $publish->result['conflicting_more'] ?? 0;
        $listed = $paths === [] ? 'none listed' : implode(', ', $paths);

        return is_int($more) && $more > 0 ? "{$listed} and {$more} more" : $listed;
    }

    private static function prefix(Task $group, Operation $publish): string
    {
        return "merge-round:{$group->id}:{$publish->id}:";
    }

    /** @return Result */
    private static function result(Task $group, Operation $publish, ?string $step, string $result, ?string $code, ?int $operation = null): array
    {
        return ['group' => $group->id, 'publish' => $publish->id, 'step' => $step, 'result' => $result, 'code' => $code, 'operation' => $operation];
    }
}
