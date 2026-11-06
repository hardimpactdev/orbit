<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskReviewRoundVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One review round of the automatic mode: the fresh reviewer turn on one pull request head, and what it found.
 *
 * @property int $id
 * @property int $task_group_id
 * @property string $head_sha
 * @property int $round
 * @property int|null $operation_id the `tasks.turn` operation of the reviewer
 * @property TaskReviewRoundVerdict|null $verdict null while the reviewer turn runs
 * @property string|null $summary
 * @property int|null $fix_turn_operation_id the author turn that fixes the findings
 * @property int|null $fix_check_operation_id the handoff check of the fix
 * @property int|null $fix_publish_operation_id the push of the checked fix
 * @property Carbon $created_at
 */
final class TaskReviewRound extends Model
{
    public const int Limit = 3;

    #[\Override]
    protected $fillable = ['task_group_id', 'head_sha', 'round', 'operation_id', 'verdict', 'summary', 'fix_turn_operation_id', 'fix_check_operation_id', 'fix_publish_operation_id'];

    /** @return BelongsTo<Task, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'task_group_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['round' => 'integer', 'verdict' => TaskReviewRoundVerdict::class];
    }
}
