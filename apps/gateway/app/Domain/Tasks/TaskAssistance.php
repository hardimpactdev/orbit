<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stores the kind and question of an assistance request.
 *
 * A failure never replaces an open direction request. A direction request replaces an open failure.
 * An open direction request changes only when the caller replaces it on purpose, such as an operator comment.
 */
final class TaskAssistance
{
    public const array Fields = ['assistance_requested', 'assistance_kind', 'assistance_question', 'assistance_reason'];

    public const string ImplementerBlockedPrefix = 'The implementer is blocked: ';

    public const string ReviewerBlockedPrefix = 'The reviewer is blocked: ';

    /** Compare the complete open request, not just its reason text. */
    public static function sameRequest(Task $left, Task $right): bool
    {
        return $left->assistance_requested && $left->only(self::Fields) === $right->only(self::Fields);
    }

    /** Call with both rows locked, before clearing the child's resolved request in the same transaction. */
    public static function clearResolvedParent(Task $task, Task $parent, ?TaskComment $source = null): void
    {
        if (self::sameRequest($task, $parent)
            && ! $parent->tasks()->whereKeyNot($task->id)->where('assistance_requested', true)->exists()) {
            self::apply($parent, source: $source);
        }
    }

    /** Resolve external subtask assistance with both rows locked; keep independent group requests. */
    public static function resolveExternalSubtask(Task $task, Task $group, ?TaskComment $source = null): void
    {
        $ownsGroupRequest = self::sameRequest($task, $group);
        self::apply($task, source: $source);
        if (! $ownsGroupRequest) {
            return;
        }
        $pending = $group->tasks()->where('assistance_requested', true)->orderBy('position')->lockForUpdate()->first();
        self::apply(
            $group,
            $pending?->assistance_kind,
            $pending?->assistance_question,
            $pending?->assistance_reason,
            replaceFailure: true,
            replaceDirection: true,
            source: $source,
        );
    }

    /**
     * Records the request against the current database row. Returns false when that row must stay as it is.
     *
     * The check and the write hold the same row lock, so a stale in-memory model cannot replace a newer direction
     * request or leave its question behind. A new cleanup failure can request assistance after a task ends.
     */
    public static function apply(Task $record, ?AssistanceKind $kind = null, ?string $question = null, ?string $reason = null, bool $replaceFailure = false, bool $replaceDirection = false, ?TaskComment $source = null, bool $reconcileStatus = false): bool
    {
        return DB::transaction(function () use ($record, $kind, $question, $reason, $replaceFailure, $replaceDirection, $source, $reconcileStatus): bool {
            $current = Task::withoutGlobalScopes()->whereKey($record->id)->lockForUpdate()->firstOrFail();
            $requested = $current->assistance_requested;
            $ended = in_array($current->status, [...TaskGroupStatus::endedStatuses(), TaskStatus::Completed, TaskStatus::Cancelled], true);
            if ($reconcileStatus) {
                if (! $ended) {
                    self::sync($record, $current);

                    return false;
                }
                $kind = $current->assistance_kind === AssistanceKind::RemovalFailure ? AssistanceKind::RemovalFailure : null;
                $reason = $current->assistance_reason;
            }
            if ($ended && $kind !== null && $kind !== AssistanceKind::RemovalFailure) {
                if ($current->assistance_kind === AssistanceKind::RemovalFailure) {
                    self::sync($record, $current);

                    return false;
                }
                $kind = null;
                $question = null;
                $reason = $current->assistance_reason;
            }
            if (! $ended && $kind !== null && $requested
                && (($current->assistance_kind === AssistanceKind::Direction && ! $replaceDirection)
                    || $kind === AssistanceKind::Observation
                    || (in_array($kind, [AssistanceKind::Failure, AssistanceKind::RemovalFailure], true) && $current->assistance_kind !== AssistanceKind::Observation && ! $replaceFailure))) {
                self::sync($record, $current);

                return false;
            }
            $attributes = [
                'assistance_requested' => $kind !== null,
                'assistance_kind' => $kind?->value,
                'assistance_question' => $kind === AssistanceKind::Direction ? $question : null,
                'assistance_reason' => $reason,
            ];
            if ($current->only(self::Fields) === [...$attributes, 'assistance_kind' => $kind]) {
                self::sync($record, $current);

                return false;
            }
            DB::table('tasks')->where('id', $record->id)->update([...$attributes, 'updated_at' => now()]);
            $current->forceFill($attributes);
            self::sync($record, $current);
            if (! $requested && ! $record->assistance_requested) {
                return true;
            }
            $description = ! $requested ? 'assistance requested' : ($record->assistance_requested ? 'assistance changed' : 'assistance cleared');
            $requestId = $source->request_id ?? request()->attributes->get('orbit.request_id');
            $sanitizer = app(CommandActivityInputSanitizer::class);
            Activity::query()->create([
                'log_name' => 'tasks',
                'description' => $description,
                'subject_type' => Task::class, 'subject_id' => $record->id,
                'properties' => [
                    ...($source === null ? [] : ['comment_id' => $source->id, 'actor' => $source->author]),
                    'task_group_id' => $record->parent_id ?? $record->id,
                    'kind' => $kind?->value,
                    'question' => $question === null ? null : $sanitizer->redactText($question),
                    'reason' => $reason === null ? null : $sanitizer->redactText($reason),
                ],
                'request_id' => is_string($requestId) ? $requestId : (string) Str::uuid(), 'command' => $source === null ? 'tasks:assistance' : 'tasks:comment', 'status' => 'completed',
            ]);
            app(TaskBroadcasts::class)->groupChanged($record->parent_id ?? $record->id);

            return true;
        });
    }

    /** Synchronize only assistance; never re-encode pending casts or reload the caller's relations. */
    private static function sync(Task $record, Task $current): void
    {
        foreach (self::Fields as $field) {
            $record->setAttribute($field, $current->getAttribute($field));
        }
        $record->syncOriginalAttributes(self::Fields);
    }

    /**
     * The question stored in a blocked reason: the text after the last `Question: `,
     * or the text after the blocked prefix when that marker is absent.
     */
    public static function questionFromBlockedReason(string $reason): string
    {
        $marker = 'Question: ';
        $position = strrpos($reason, $marker);
        if ($position !== false) {
            return substr($reason, $position + strlen($marker));
        }
        foreach ([self::ImplementerBlockedPrefix, self::ReviewerBlockedPrefix] as $prefix) {
            if (str_starts_with($reason, $prefix)) {
                return substr($reason, strlen($prefix));
            }
        }

        return $reason;
    }
}
