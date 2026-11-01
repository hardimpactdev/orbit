<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Activity;
use App\Models\Operation;
use App\Models\Task;
use App\Models\TaskVm;

/** Recorded ownership and work facts. Only the native schedule applies age policy. */
final readonly class TaskVmReaperInput
{
    /** @return array{vms:list<array<string,mixed>>} */
    public function capture(): array
    {
        return ['vms' => array_values(TaskVm::query()->live()->with(['group.checks', 'group.tasks.checks'])->orderBy('id')
            ->get()->map($this->vm(...))->all())];
    }

    /** @return array<string,mixed> */
    public function vm(TaskVm $vm): array
    {
        $group = $vm->group;
        $progressAt = $vm->claimed_at ?? $vm->created_at ?? throw new \LogicException('A recorded VM must have a creation time.');
        $progress = [$progressAt];
        $assistance = [];
        if ($group instanceof Task) {
            foreach ([$group, ...$group->tasks] as $task) {
                $progress[] = $task->created_at;
                $progress[] = $task->started_at;
                if (! in_array($task->status, [TaskStatus::Cancelled, TaskGroupStatus::Cancelled], true)) {
                    $progress[] = $task->settled_at;
                }
                foreach ($task->checks as $check) {
                    $progress[] = $check->started_at;
                    if ($check->status !== TaskCheckStatus::Cancelled) {
                        $progress[] = $check->finished_at;
                    }
                }
                if ($task->assistance_requested) {
                    $request = Activity::query()->where('subject_type', $task->getMorphClass())->where('subject_id', $task->id)
                        ->where('description', 'assistance requested')->latest('id')->first();
                    if ($request !== null) {
                        $assistance[] = $request->created_at;
                    }
                }
            }
            foreach (Operation::query()->where('target_type', 'task_group')->where('target_id', $group->id)
                ->whereIn('kind', ['tasks.turn', 'tasks.check'])->get() as $operation) {
                $progress[] = $operation->started_at;
                if ($operation->status !== OperationStatus::Cancelled) {
                    $progress[] = $operation->finished_at;
                }
            }
        }
        foreach ($progress as $timestamp) {
            if ($timestamp !== null && $timestamp->greaterThan($progressAt)) {
                $progressAt = $timestamp;
            }
        }
        $requestedAt = null;
        foreach ($assistance as $timestamp) {
            if ($timestamp !== null && ($requestedAt === null || $timestamp->lessThan($requestedAt))) {
                $requestedAt = $timestamp;
            }
        }

        $ssh = null;
        if ($vm->node !== null) {
            try {
                $ssh = app(SwampSshExecutionInput::class)->forNode($vm->node);
            } catch (ResourceOperationException) {
                // No pinned access means no native idle proof; age limits remain usable.
            }
        }

        return [
            'task_vm_id' => $vm->id, 'provider_id' => $vm->provider_id, 'name' => $vm->name,
            'node_id' => $vm->node_id, 'host_node_id' => $vm->host_node_id,
            'group_id' => $vm->group_id, 'group_title' => $group?->title, 'ssh' => $ssh, 'claimed_at' => $vm->claimed_at?->toISOString(),
            'project_id' => $group?->project_id, 'group_created_at' => $group?->created_at?->toISOString(),
            'group_live' => $group !== null && ! in_array($group->status, TaskGroupStatus::endedStatuses(), true),
            'progress_at' => $progressAt->toISOString(),
            'assistance_requested_at' => $requestedAt?->toISOString(),
        ];
    }
}
