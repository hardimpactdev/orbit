<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\TaskBroadcastObserver;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckProcess;
use App\Domain\Tasks\TaskCheckStatus;
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
 * @property string|null $commit_sha the handoff commit that CI tests, once Orbit committed it ([Handoff CI](/reference/tasks#handoff-ci))
 * @property Carbon|null $pushed_at when Orbit saw that commit pushed for CI
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
#[ObservedBy([TaskBroadcastObserver::class])]
final class TaskCheck extends Model
{
    #[\Override]
    protected $fillable = [
        'task_id', 'task_comment_id', 'kind', 'failed_step', 'status', 'pid', 'process_started', 'head_before', 'tree_before',
        'head_after', 'tree_after', 'exit_code', 'changed_paths', 'output', 'deliverable_evidence', 'commit_sha', 'pushed_at', 'started_at', 'finished_at',
    ];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    public function process(): TaskCheckProcess
    {
        $runId = TaskWorkspaceLeaseRecord::query()->where('check_id', $this->id)->where('pid', $this->pid)
            ->where('process_started', $this->process_started)->value('check_run_id');

        return new TaskCheckProcess($this->pid, $this->process_started, $this->head_before, $this->tree_before, is_string($runId) ? $runId : null);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => TaskCheckKind::class,
            'status' => TaskCheckStatus::class,
            'pid' => 'integer',
            'exit_code' => 'integer',
            'changed_paths' => 'array',
            'deliverable_evidence' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'pushed_at' => 'datetime',
        ];
    }
}
