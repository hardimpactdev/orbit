<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Actions\Operations\StartOperationAction;
use App\Data\Schedules\ScheduleData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Swamp\SwampCommandStep;
use App\Infrastructure\Swamp\SwampScheduleWorkflows;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Schedule;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * One Schedule is one Swamp workflow, schedule-<id>, with its own cron trigger and its intent fixed inside (ADR 0244).
 * Create, enable and destroy touch no Node, so each is answered at once as a succeeded task (R1).
 */
final readonly class NativeSchedules
{
    public const Vault = 'orbit-control-inputs';

    public function __construct(private SwampScheduleWorkflows $workflows, private ScheduleTargetResolver $targets,
        private StartOperationAction $operations, private RecordEventBroadcaster $broadcaster) {}

    public static function workflowName(Schedule $schedule): string
    {
        return 'schedule-'.$schedule->id;
    }

    public function create(#[SensitiveParameter] Schedule $schedule, bool $created, ?Node $caller, ?string $requestId): Operation
    {
        $input = $this->input($schedule, $caller, ['created' => $created]);
        try {
            $target = $this->targets->placementOf($schedule);
            app(SwampCommandStep::class)->executionCopy(self::Vault, self::commandKey($schedule), $schedule->command);
            $workflowId = $this->workflows->write($schedule->swamp_workflow_id, self::workflowName($schedule),
                fn (string $id): string => $this->content($schedule, $target, $id));
            $schedule->update(['swamp_workflow_id' => $workflowId]);
            $this->applyTrigger($schedule, $schedule->desired_timer_state);
        } catch (ResourceOperationException $exception) {
            $schedule->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'publish', 'error_code' => $exception->errorCode]);

            throw $exception;
        }

        return $this->record($schedule, 'create', $input, $requestId, [
            'status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null,
        ], $created ? RecordEventType::ScheduleCreated : RecordEventType::ScheduleUpdated);
    }

    public function enable(Schedule $schedule, ?Node $caller, ?string $requestId): Operation
    {
        $this->targets->placementOf($schedule);
        $input = $this->input($schedule, $caller);
        $this->applyTrigger($schedule, DesiredTimerState::Enabled);

        return $this->record($schedule, 'enable', $input, $requestId, ['desired_timer_state' => DesiredTimerState::Enabled], RecordEventType::ScheduleUpdated);
    }

    public function destroy(Schedule $schedule, ?Node $caller, ?string $requestId): Operation
    {
        $input = ['id' => $schedule->id, 'node_id' => $schedule->host_node_id, 'caller_node_id' => $caller?->id, 'cascade' => false];
        $this->remove($schedule);

        return DB::transaction(function () use ($schedule, $input, $requestId): Operation {
            $operation = $this->operations->record('schedule.destroy', self::owner($schedule), $schedule->target_id, $schedule->host_node_id,
                $input, ['ready' => true], 'operator', $requestId);
            $schedule->delete();
            $this->broadcaster->broadcast(RecordEventType::ScheduleDeleted, $schedule->id, ['id' => $schedule->id, 'name' => $schedule->name]);

            return $operation;
        });
    }

    /** Removes the trigger, then the workflow; a fire that already started finishes and is still recorded. */
    public function remove(Schedule $schedule): void
    {
        $schedule->update(['status' => LifecycleStatus::Removing, 'failed_step' => null, 'error_code' => null]);
        try {
            $this->workflows->removeTrigger(self::workflowName($schedule));
            if ($schedule->swamp_workflow_id !== null) {
                $this->workflows->delete($schedule->swamp_workflow_id);
            }
        } catch (ResourceOperationException $exception) {
            $schedule->update(['failed_step' => 'remove', 'error_code' => $exception->errorCode]);

            throw $exception;
        }
    }

    /** Public intent of a native change; the command is a secret reference, never its bytes.
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function input(Schedule $schedule, ?Node $caller, array $extra = []): array
    {
        return ['id' => $schedule->id, 'node_id' => $schedule->host_node_id, 'caller_node_id' => $caller?->id, ...$extra,
            'calendar' => $schedule->calendar, 'timeout_seconds' => $schedule->timeout_seconds, 'desired' => $schedule->desired_timer_state->value,
            'command_hash' => hash('sha256', $schedule->command), 'command' => ['$secret' => 'schedule.command', 'id' => $schedule->target_id]];
    }

    public static function owner(Schedule $schedule): string
    {
        return Instance::isMorphType($schedule->target_type) ? 'instance_schedules' : 'node_schedules';
    }

    public static function commandKey(Schedule $schedule): string
    {
        return 'schedule-command-'.$schedule->id;
    }

    private function applyTrigger(Schedule $schedule, DesiredTimerState $desired): void
    {
        $desired === DesiredTimerState::Enabled
            ? $this->workflows->setTrigger(self::workflowName($schedule), $schedule->calendar)
            : $this->workflows->removeTrigger(self::workflowName($schedule));
    }

    /** @param array<string,mixed> $input
     * @param  array<string,mixed>  $changes
     */
    private function record(Schedule $schedule, string $verb, array $input, ?string $requestId, array $changes, RecordEventType $event): Operation
    {
        return DB::transaction(function () use ($schedule, $verb, $input, $requestId, $changes, $event): Operation {
            $operation = $this->operations->record('schedule.'.$verb, self::owner($schedule), $schedule->target_id, $schedule->host_node_id,
                $input, ['ready' => true], 'operator', $requestId);
            $schedule->update($changes);
            $this->broadcaster->broadcast($event, $schedule->id, ScheduleData::fromModel($schedule)->toArray());

            return $operation;
        });
    }

    /** JSON is YAML; serve validates the whole workflow before it saves it. */
    private function content(#[SensitiveParameter] Schedule $schedule, ScheduleTarget $target, string $workflowId): string
    {
        $intent = ['schedule' => ['id' => $schedule->id, 'command_hash' => hash('sha256', $schedule->command), 'user' => $target->user,
            'working_directory' => $target->workingDirectory ?? '', 'shell' => $target->shell ?? '', 'login_shell' => $target->loginShell, 'timeout_seconds' => $schedule->timeout_seconds],
            'ssh_execution' => app(SwampSshExecutionInput::class)->forNode($target->node)];

        return json_encode([
            'id' => $workflowId,
            'name' => self::workflowName($schedule),
            'description' => 'Orbit Schedule '.$schedule->name.'; fires run task-command-run with this fixed intent.',
            // The Gateway's run reader records each root run as a task from these tags, also after the Schedule is gone.
            'tags' => ['orbit_schedule_id' => $schedule->id, 'orbit_node_id' => (string) $target->node->id,
                'orbit_target_type' => self::owner($schedule), 'orbit_target_id' => (string) $schedule->target_id],
            'vaults' => [self::Vault],
            'queueTimeout' => 300,
            'jobs' => [['name' => 'fire', 'target' => 'orbit-node-209', 'steps' => [[
                'name' => 'Run the Schedule', 'target' => 'orbit-node-209',
                'task' => ['type' => 'workflow', 'workflowIdOrName' => 'task-command-run', 'inputs' => [
                    'node_id' => $target->node->id, 'kind' => 'schedule.run', 'intent' => $intent,
                    'execution_secrets' => ['command' => ['vault' => self::Vault, 'key' => self::commandKey($schedule)]],
                ]],
            ]]]],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
