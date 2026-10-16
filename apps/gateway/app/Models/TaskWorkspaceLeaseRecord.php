<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property Carbon|null $acquired_at
 * @property int|null $task_id
 * @property int|null $acquisition_sequence
 * @property int $id
 * @property int $instance_id
 * @property int $group_id
 * @property string $kind
 * @property string $owner
 * @property string $status
 * @property int|null $thread_id
 * @property int|null $check_id
 * @property int|null $pid
 * @property string|null $dispatch_key
 * @property string|null $process_head
 * @property string|null $process_tree
 * @property string|null $process_started
 * @property string|null $head_before
 * @property string|null $tree_before
 * @property string|null $head_after
 * @property string|null $tree_after
 */
final class TaskWorkspaceLeaseRecord extends Model
{
    #[\Override]
    protected $table = 'task_workspace_leases';

    #[\Override]
    protected $guarded = [];

    protected function casts(): array
    {
        return ['review_baseline_required' => 'boolean', 'dispatch_completed' => 'boolean', 'orbit_mutation' => 'boolean', 'remote_uncertain' => 'boolean', 'adopted' => 'boolean', 'saw_working' => 'boolean', 'dispatch_started_at' => 'datetime', 'active_instance_id' => 'integer', 'instance_id' => 'integer', 'group_id' => 'integer', 'acquisition_sequence' => 'integer', 'task_id' => 'integer', 'thread_id' => 'integer', 'check_id' => 'integer', 'pid' => 'integer', 'gateway_pid' => 'integer', 'acquired_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
