<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskReviewRoundVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One review round of the automatic mode: one Swamp run that reviews one pull request head with a fresh reviewer, comments the
 * verdict, and for findings fixes, checks and pushes them.
 *
 * @property int $id
 * @property int $task_group_id
 * @property string $head_sha
 * @property int $round
 * @property int|null $operation_id the `tasks.review_round` operation of the run
 * @property TaskReviewRoundVerdict|null $verdict null while the run goes on
 * @property string|null $summary the summary of the reviewer
 * @property string|null $pushed_commit the commit that the run pushed to the branch of the pull request, when the fix was pushed
 * @property int|null $check_id the local check of the fix
 * @property Carbon $created_at
 */
final class TaskReviewRound extends Model
{
    public const int Limit = 3;

    #[\Override]
    protected $fillable = ['task_group_id', 'head_sha', 'round', 'operation_id', 'verdict', 'summary', 'pushed_commit', 'check_id'];

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
