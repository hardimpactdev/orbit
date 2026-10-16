<?php

declare(strict_types=1);

namespace App\Actions\TaskVms;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmState;
use App\Infrastructure\TaskVms\TaskVmRoleImages;
use App\Models\AgentThread;
use App\Models\Task;
use App\Models\TaskVm;

/** Restores a waiting group's pinned image transfer without resetting its VM or admitting work. */
final readonly class RetryTaskVmRoleImagesAction
{
    public function __construct(
        private TaskExecutionLock $lock,
        private AgentThreadObserver $observer,
        private TaskVmRoleImages $images,
    ) {}

    public function execute(int $groupId, int $vmId): void
    {
        $this->lock->synchronized($groupId, function () use ($groupId, $vmId): void {
            $group = Task::topLevel()->find($groupId);
            $vm = TaskVm::query()->find($vmId);
            if (! $group instanceof Task || ! $vm instanceof TaskVm || $vm->group_id !== $group->id
                || ! in_array($group->status, TaskGroupStatus::active(), true) || TaskExecutionHold::active($group)
                || ! $group->assistance_requested || $vm->state !== TaskVmState::Ready
                || $vm->provider !== TaskVmProviderKind::Incus || $vm->node?->status !== LifecycleStatus::Active
                || $vm->recipe_identity === null || $vm->size->resources()->topologyPool === null
                || $vm->topology_state !== null || $vm->topology_run !== null) {
                throw new TaskVmException('task_vm.recipe_images_retry_refused', 'Retry requires the waiting group\'s ready Incus VM, a pinned recipe, and no topology run.');
            }
            foreach (AgentThread::query()->where('task_group_id', $groupId)->whereNull('archived_at')->get() as $thread) {
                if ($thread->sendLeases()->where('expires_at', '>', now())->exists()
                    || ! in_array($this->observer->observe($thread)?->state, [AgentThreadState::Done, AgentThreadState::Failed, AgentThreadState::Idle], true)) {
                    throw new TaskVmException('task_vm.recipe_images_retry_refused', 'An agent is active or its state is unknown. Retry after the group is idle.');
                }
            }
            $this->images->prepare($vm);
        });
    }
}
