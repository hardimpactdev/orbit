<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;

/** One ownership predicate for discovery and the final removal check. Unknown or conflicting ownership stays. */
final readonly class TaskWorkspaceOwnership
{
    /** @return Builder<Instance> */
    public static function query(): Builder
    {
        return Instance::query()->select('instances.*')
            ->join('tasks', function ($join): void {
                $join->on('tasks.project_id', '=', 'instances.project_id')->whereNull('tasks.parent_id')
                    ->where(function ($proof): void {
                        $proof->where(function ($attached): void {
                            $attached->where('tasks.taskable_type', TaskableType::Instance)
                                ->whereColumn('tasks.taskable_id', 'instances.id');
                        })->orWhere(function ($owned): void {
                            $owned->whereNull('tasks.taskable_id')->whereNull('tasks.taskable_type')
                                ->whereColumn('instances.task_workspace_owner_id', 'tasks.id');
                        });
                    });
            })
            ->where(function ($owner): void {
                $owner->whereNull('instances.task_workspace_owner_id')
                    ->orWhereColumn('instances.task_workspace_owner_id', 'tasks.id');
            })
            ->whereNotExists(function ($vm): void {
                $vm->selectRaw('1')->from('task_vms')->whereColumn('task_vms.node_id', 'instances.node_id')
                    ->where('task_vms.state', '!=', 'destroyed')
                    ->where(function ($owner): void {
                        $owner->whereNull('task_vms.group_id')->orWhereColumn('task_vms.group_id', '!=', 'tasks.id');
                    });
            })
            ->whereNotExists(function ($other): void {
                $other->selectRaw('1')->from('tasks as other_tasks')->whereNull('other_tasks.parent_id')
                    ->where('other_tasks.taskable_type', TaskableType::Instance)
                    ->whereColumn('other_tasks.taskable_id', 'instances.id')->whereColumn('other_tasks.id', '!=', 'tasks.id');
            });
    }

    /** @return Builder<Instance> */
    public static function forGroup(Task $group): Builder
    {
        return self::query()->where('tasks.id', $group->id);
    }

    public static function owns(Task $group, Instance $instance): bool
    {
        return self::forGroup($group)->where('instances.id', $instance->id)->exists();
    }
}
