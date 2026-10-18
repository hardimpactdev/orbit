<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Database\Query\Builder;
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
    public const string ImplementerBlockedPrefix = 'The implementer is blocked: ';

    public const string ReviewerBlockedPrefix = 'The reviewer is blocked: ';

    /** Compare the complete open request, not just its reason text. */
    public static function sameRequest(Task $left, Task $right): bool
    {
        $fields = ['assistance_requested', 'assistance_kind', 'assistance_question', 'assistance_reason'];

        return $left->assistance_requested && $left->only($fields) === $right->only($fields);
    }

    /** Call with both rows locked, before clearing the child's resolved request in the same transaction. */
    public static function clearResolvedParent(Task $task, Task $parent): void
    {
        if (self::sameRequest($task, $parent)
            && ! $parent->tasks()->whereKeyNot($task->id)->where('assistance_requested', true)->exists()) {
            self::apply($parent);
        }
    }

    /**
     * Records the request against the current database row. Returns false when that row must stay as it is.
     *
     * The check and the write are one update, so a stale in-memory model cannot replace a newer direction
     * request or leave its question behind. Ended tasks keep the reason but never ask for assistance.
     */
    public static function apply(Task $record, ?AssistanceKind $kind = null, ?string $question = null, ?string $reason = null, bool $replaceFailure = false, bool $replaceDirection = false, ?TaskComment $source = null): bool
    {
        return DB::transaction(function () use ($record, $kind, $question, $reason, $replaceFailure, $replaceDirection, $source): bool {
            $attributes = [
                'assistance_requested' => $kind === null ? false : DB::raw("CASE WHEN status IN ('completed', 'cancelled') THEN 0 ELSE 1 END"),
                'assistance_kind' => $kind?->value,
                'assistance_question' => $kind === AssistanceKind::Direction ? $question : null,
                'assistance_reason' => $reason,
            ];
            $query = DB::table('tasks')->where('id', $record->id);
            if ($kind !== null) {
                $query->where(function (Builder $query) use ($kind, $replaceFailure, $replaceDirection): void {
                    $query->where('assistance_requested', false)
                        ->orWhere(function (Builder $open) use ($kind, $replaceFailure, $replaceDirection): void {
                            if (! $replaceDirection) {
                                $open->where(function (Builder $direction): void {
                                    $direction->whereNull('assistance_kind')->orWhere('assistance_kind', '!=', AssistanceKind::Direction->value);
                                });
                            }
                            if ($kind === AssistanceKind::Failure && ! $replaceFailure) {
                                $open->whereRaw('1 = 0');
                            } elseif ($replaceDirection) {
                                $open->whereRaw('1 = 1');
                            }
                        });
                });
            }
            $query->where(function (Builder $different) use ($kind, $attributes): void {
                if ($kind === null) {
                    $different->where('assistance_requested', true);
                } else {
                    $different->whereRaw("assistance_requested != CASE WHEN status IN ('completed', 'cancelled') THEN 0 ELSE 1 END");
                }
                foreach (['assistance_kind', 'assistance_question', 'assistance_reason'] as $field) {
                    $value = $attributes[$field];
                    if ($value === null) {
                        $different->orWhereNotNull($field);
                    } else {
                        $different->orWhereNull($field)->orWhere($field, '!=', $value);
                    }
                }
            });
            $updated = $query->update([...$attributes, 'updated_at' => now()]);
            $pending = array_diff_key($record->getDirty(), $attributes);
            $record->refresh()->forceFill($pending);
            if ($updated === 0) {
                return false;
            }
            $sanitizer = app(CommandActivityInputSanitizer::class);
            Activity::query()->create([
                'log_name' => 'tasks',
                'description' => $record->assistance_requested ? 'assistance requested' : 'assistance cleared',
                'subject_type' => Task::class, 'subject_id' => $record->id,
                'properties' => [
                    ...($source === null ? [] : ['comment_id' => $source->id, 'actor' => $source->author]),
                    'task_group_id' => $record->parent_id ?? $record->id,
                    'kind' => $kind?->value,
                    'question' => $question === null ? null : $sanitizer->redactText($question),
                    'reason' => $reason === null ? null : $sanitizer->redactText($reason),
                ],
                'request_id' => (string) Str::uuid(), 'command' => $source === null ? 'tasks:assistance' : 'tasks:comment', 'status' => 'completed',
            ]);
            app(TaskBroadcasts::class)->groupChanged($record->parent_id ?? $record->id);

            return true;
        });
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
