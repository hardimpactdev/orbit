<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Data\Tasks\StartTaskPublishData;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskCheck;
use Illuminate\Database\Eloquent\Builder;

/**
 * The publish of a task copy's done check, for the tick and for the release call alike (docs/reference/tasks.md#publish-by-itself):
 * the reviewer's title and body (docs/reference/tasks.md#the-reviewers-pull-request-text), the issue the group closes, and a ready
 * pull request. The publish's `.env` content check and the copy check read this title and body too.
 */
final class TaskDonePublication
{
    /** A publish whose step waited too long for a Swamp behavior lock: no refusal, the tick starts the same check again. */
    public const string LockTimeoutCode = 'tasks.publish_lock_timeout';

    /** A done group whose reviewer thread's newest turn sent no pull request text. */
    public const string TextMissingCode = 'tasks.pr_text_missing';

    /** The limit of a pull request body that the publish request accepts. */
    public const int BodyLimit = 65536;

    /** The limit of the reviewer's body: the closing line and the waiver lines still fit within {@see self::BodyLimit}. */
    public const int ReviewerBodyLimit = 60000;

    /** How many publishes of one done check may time out on a lock, the first and its retries, before the check shows as refused. */
    public const int LockTimeoutAttempts = 3;

    /** A group that has its pull request pushes the check only: the open pull request shows the new commits and keeps its title and body. */
    public static function for(Task $group, TaskCheck $check): StartTaskPublishData
    {
        if ($group->pr_url !== null) {
            return new StartTaskPublishData($check->id);
        }
        $text = TaskGroupDone::prText($group, $check) ?? throw new ResourceOperationException(self::TextMissingCode,
            'The task group\'s work is done, but its reviewer sent no pull request text. A reviewer turn that approves the commit sends it.', 409);

        return new StartTaskPublishData($check->id, ['title' => $text['title'], 'body' => $text['body'], 'draft' => false]);
    }

    /**
     * Why the publish of a named check without a pull request text opens no pull request, for a group that has none yet, or null when
     * it opens one: only the reviewed done check with the reviewer's text does (docs/reference/tasks.md#publish-an-external-group).
     */
    public static function withheld(Task $group, int $check, ?TaskCheck $done): ?string
    {
        return match (true) {
            $done === null => 'The group has no done check now, so no pull request opened: it opens when the reviewer approved this check, its subtask is completed and no other work of the group runs.',
            $done->id !== $check => "Check {$check} is not the group's done check {$done->id}, so no pull request opened.",
            TaskGroupDone::prText($group, $done) === null => "The reviewer sent no pull request text for check {$check}, so no pull request opened.",
            default => null,
        };
    }

    /**
     * The body, with `Closes #<issue>` at its end for a group that names its issue and whose body does not close it already
     * (docs/reference/tasks.md#closes-the-issue). Every publish with a pull request text applies it: the reviewer's and a person's.
     */
    public static function closing(Task $group, string $body): string
    {
        $issue = $group->issue_number;
        if ($issue === null || preg_match('/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?)\b:?\s+#'.$issue.'(?!\d)/i', $body) === 1) {
            return $body;
        }

        return rtrim($body)."\n\nCloses #{$issue}";
    }

    /**
     * The publish of this done check, by any caller, that ended without success and keeps the check refused: Orbit does not start the check
     * again by itself, and the group shows it as refused (docs/reference/tasks.md#a-refused-automatic-publish). The newest publish that
     * failed with another code than {@see self::LockTimeoutCode}, or was cancelled or interrupted without one, refuses the check whatever
     * came after. Lock timeouts alone refuse it once {@see self::LockTimeoutAttempts} publishes of the check timed out on a lock, and at once
     * for a group the tick does not publish: its mode, or the mode its done check recorded, is not `pr_when_done`.
     */
    public static function unsuccessful(Task $group, TaskCheck $check, ?TaskPrMode $mode): ?Operation
    {
        $ended = self::publishes($group, $check)->whereIn('status', [OperationStatus::Failed->value, OperationStatus::Cancelled->value, OperationStatus::Interrupted->value]);
        $refused = (clone $ended)->where(static fn (Builder $query): Builder => $query->whereNull('error_code')->orWhere('error_code', '!=', self::LockTimeoutCode))
            ->orderByDesc('id')->first();
        $retried = $mode === TaskPrMode::PrWhenDone && $check->pr_mode === TaskPrMode::PrWhenDone;
        if ($refused instanceof Operation || ($retried && self::lockTimeouts($group, $check) < self::LockTimeoutAttempts)) {
            return $refused;
        }

        return $ended->orderByDesc('id')->first();
    }

    /** The attempt of the tick's next publish of this check: one more than its publishes that timed out on a lock, by any caller. */
    public static function attempt(Task $group, TaskCheck $check): int
    {
        return self::lockTimeouts($group, $check) + 1;
    }

    private static function lockTimeouts(Task $group, TaskCheck $check): int
    {
        return self::publishes($group, $check)->whereIn('status', [OperationStatus::Failed->value, OperationStatus::Interrupted->value])
            ->where('error_code', self::LockTimeoutCode)->count();
    }

    /** @return Builder<Operation> */
    private static function publishes(Task $group, TaskCheck $check): Builder
    {
        return Operation::query()->where('kind', OperationKinds::TasksPublish)->where('target_type', OperationKinds::TaskGroupTarget)->where('target_id', $group->id)
            ->where('input->check', $check->id);
    }

    /** The code a refused publish shows: its own, or `operation.cancelled` and the like when it ended without one. */
    public static function code(Operation $operation): string
    {
        return $operation->error_code ?? 'operation.'.$operation->status->value;
    }
}
