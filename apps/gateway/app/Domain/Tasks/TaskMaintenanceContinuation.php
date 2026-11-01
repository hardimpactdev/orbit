<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Actions\Tasks\StartTaskCheckAction;
use App\Actions\Tasks\StartTaskParkAction;
use App\Actions\Tasks\StartTaskPublishAction;
use App\Actions\Tasks\StartTaskTurnAction;
use App\Data\Tasks\StartTaskCheckData;
use App\Data\Tasks\StartTaskPublishData;
use App\Data\Tasks\TaskTurnData;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Models\Task;
use App\Support\ValidatedData;

/** Terminal results alone request the next normal guarded maintenance admission. */
final readonly class TaskMaintenanceContinuation
{
    public function apply(Operation $operation): void
    {
        if (! $operation->status->terminal() || ($operation->state['maintenance_continued'] ?? false)) {
            return;
        }
        $group = Task::topLevel()->lockForUpdate()->findOrFail($operation->target_id);
        $state = $group->maintenance;
        if (! is_array($state) || in_array($group->status, TaskGroupStatus::endedStatuses(), true)) {
            return;
        }
        if (($state['operation_id'] ?? $operation->id) !== $operation->id
            || ! in_array($operation->kind, ['tasks.provision', 'tasks.maintenance', 'tasks.check', 'tasks.turn', 'tasks.publish'], true)) {
            return;
        }
        $operation->update(['state' => [...($operation->state ?? []), 'maintenance_continued' => true]]);
        if ($operation->status !== OperationStatus::Succeeded) {
            TaskAssistance::apply($group, AssistanceKind::Failure, reason: 'Native maintenance stopped: '.($operation->error_code ?? $operation->status->value));

            return;
        }
        $child = $group->tasks()->orderBy('position')->firstOrFail();
        $kind = match ($operation->kind) {
            'tasks.provision' => 'tasks.maintenance',
            'tasks.maintenance' => 'tasks.check',
            'tasks.turn' => 'tasks.maintenance',
            'tasks.check' => ($operation->result['outcome'] ?? null) === 'passed' ? (($state['changed'] ?? true) === false ? 'tasks.maintenance_finish' : 'tasks.publish') : 'tasks.turn',
            'tasks.publish' => 'tasks.park',
        };
        if ($operation->kind === 'tasks.check') {
            $state['check_operation_id'] = $operation->id;
        }
        if ($operation->kind === 'tasks.maintenance') {
            $state['changed'] = $operation->result['changed'] ?? true;
            $state['run_id'] = $operation->state['swamp_run_id'] ?? null;
        }
        if ($kind === 'tasks.turn') {
            if (($state['repair_attempted'] ?? false) || ! in_array($operation->result['outcome'] ?? null, ['failed', 'changed'], true)) {
                TaskAssistance::apply($group, AssistanceKind::Failure, reason: 'Maintenance requires investigation after its bounded repair/check attempt.');

                return;
            }
            $state['repair_attempted'] = true;
        }
        $identity = app(OperationIdempotency::class);
        $prior = [$identity->kind, $identity->key, $identity->fingerprint];
        $identity->kind = $kind;
        $identity->key = 'maintenance:'.$group->id.':'.$operation->id.':'.$kind;
        $identity->fingerprint = hash('sha256', $identity->key);
        try {
            if ($kind === 'tasks.maintenance_finish') {
                $child->update(['status' => TaskStatus::Completed, 'settled_at' => now(), 'completion_summary' => 'No dependency changes; native check passed.']);
                $group->update(['status' => TaskGroupStatus::Settling, 'maintenance' => $state]);
                $next = app(TaskCancelIntent::class)->finishMaintenance($group);
            } elseif ($kind === 'tasks.maintenance') {
                $group->update(['maintenance' => $state]);
                $next = app(TaskMaintenanceIntent::class)->start($group);
            } elseif ($kind === 'tasks.check') {
                $next = app(StartTaskCheckAction::class)->execute($group, new StartTaskCheckData($child->id, TaskCheckKind::Handoff), 'project-maintenance', null);
            } elseif ($kind === 'tasks.turn') {
                $prompt = 'Repair the failed dependency check in this checkout. Preserve manifest range style, release-age and advisory policy. Change no unrelated packages. This is the single maintenance repair turn. The native maintenance workflow will rerun and verify the exact captured batch. Captured intent: '.json_encode($state, JSON_THROW_ON_ERROR);
                $next = app(StartTaskTurnAction::class)->execute($group, new TaskTurnData($child->id, 'implementer', $prompt, 'new'), 'project-maintenance', null);
            } elseif ($kind === 'tasks.publish') {
                $next = app(StartTaskPublishAction::class)->execute($group, new StartTaskPublishData(ValidatedData::integer($operation->input['check_id']),
                    ['title' => $group->title, 'body' => $group->brief]), 'project-maintenance', null);
            } else {
                $child->update(['status' => TaskStatus::Completed, 'settled_at' => now()]);
                $group->refresh();
                $next = app(StartTaskParkAction::class)->execute($group, 'project-maintenance', null);
            }
            if ($next->kind === 'tasks.publish') {
                $state['publish_operation_id'] = $next->id;
            }
            $group->update(['maintenance' => [...$state, 'stage' => $next->kind, 'operation_id' => $next->id]]);
        } catch (ResourceOperationException $exception) {
            TaskAssistance::apply($group, AssistanceKind::Failure, reason: 'Maintenance admission refused: '.$exception->errorCode);
        } finally {
            [$identity->kind, $identity->key, $identity->fingerprint] = $prior;
        }
    }
}
