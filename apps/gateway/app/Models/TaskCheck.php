<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskBroadcastObserver;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskInsightObserver;
use App\Domain\Tasks\TaskPrMode;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One run of the Project check: the baseline before the first implementer, or the check for one `ready_for_review` receipt.
 *
 * @property int $id
 * @property int $task_id
 * @property int|null $task_comment_id
 * @property TaskCheckKind $kind
 * @property string|null $failed_step
 * @property TaskCheckStatus $status
 * @property int $pid
 * @property string $process_started
 * @property string $head_before
 * @property string $tree_before
 * @property string|null $head_after
 * @property string|null $tree_after
 * @property int|null $exit_code
 * @property list<string>|null $changed_paths
 * @property string|null $output
 * @property array<string, mixed>|null $deliverable_evidence
 * @property list<array{name: string, result: string, seconds: float}>|null $parts each part of `bin/task-check` with its result and seconds ([Handoffs](/reference/tasks#handoffs))
 * @property string|null $commit_sha the handoff commit that CI tests, once Orbit committed it ([Handoff CI](/reference/tasks#handoff-ci))
 * @property Carbon|null $pushed_at when Orbit saw that commit pushed for CI
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property TaskPrMode|null $pr_mode the group's mode when this handoff check passed ([Task group modes](/reference/tasks#where-the-mode-comes-from))
 * @property string|null $pr_title the reviewer's pull request title on this check ([The reviewer's pull request text](/reference/tasks#the-reviewers-pull-request-text))
 * @property string|null $pr_body the reviewer's pull request body on this check
 * @property int|null $pr_text_operation_id the reviewer turn that sent the title and the body
 * @property array{verdict: string, findings: int}|null $review_receipt the review receipt that a reviewer turn posted on this check ([The review receipt](/reference/tasks#the-review-receipt))
 * @property int|null $review_receipt_operation_id the reviewer turn that posted it
 */
#[ObservedBy([TaskBroadcastObserver::class, TaskInsightObserver::class])]
final class TaskCheck extends Model
{
    #[\Override]
    protected $fillable = [
        'task_id', 'task_comment_id', 'kind', 'failed_step', 'status', 'pid', 'process_started', 'head_before', 'tree_before',
        'head_after', 'tree_after', 'exit_code', 'changed_paths', 'output', 'deliverable_evidence', 'parts', 'commit_sha', 'pushed_at', 'started_at', 'finished_at', 'pr_mode',
        'pr_title', 'pr_body', 'pr_text_operation_id', 'review_receipt', 'review_receipt_operation_id',
    ];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TaskCheckKind::class,
            'status' => TaskCheckStatus::class,
            'pr_mode' => TaskPrMode::class,
            'pid' => 'integer',
            'pr_text_operation_id' => 'integer',
            'review_receipt' => 'array',
            'review_receipt_operation_id' => 'integer',
            'exit_code' => 'integer',
            'changed_paths' => 'array',
            'deliverable_evidence' => 'array',
            'parts' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pushed_at' => 'datetime',
        ];
    }
}
