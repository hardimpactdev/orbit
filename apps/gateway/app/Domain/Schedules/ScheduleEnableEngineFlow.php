<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;
use LogicException;

/**
 * `schedule:enable` of a retained host Node timer on the state machine (ADR 0271; docs/cli/schedule/engine/enable.mmd). One
 * step: `schedule.ensure_timer` on the Schedule's Node enables and starts the Schedule's own timer and reads back its
 * enablement, state and next elapse, as Swamp's `Check the timer` did. The end records through the Schedule's own projection.
 *
 * A native cron Schedule never reaches here (NativeSchedules sets its Swamp trigger and records the Operation); a timer in a
 * production release (`production_control`) or one that requests a run inside an Instance container (`container`) stays on
 * Swamp (EngineUnsupported).
 */
final readonly class ScheduleEnableEngineFlow implements EngineDefinition
{
    public const string Action = 'schedule.ensure_timer';

    private const string Step = 'timer';

    public function __construct(private ScheduleControlProjection $projection) {}

    public function steps(Operation $operation): array
    {
        $node = self::admitted($operation);
        $id = ValidatedData::string($operation->input['id']);

        // The target is the Node's part for this Schedule.
        return [new StepSpec(self::Step, $node, self::Action, "{$node}:schedule-{$id}", budgetMs: 60_000, resendMs: 10_000)];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        if ($step !== self::Step) {
            throw new LogicException("Unknown Schedule step [{$step}].");
        }
        self::admitted($operation);
        $id = ValidatedData::string($operation->input['id']);

        return ['schedule_id' => $id, 'unit' => "orbit-schedule-{$id}.timer"];
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException('The Schedule flow has no Gateway step.');
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        $data = match (true) {
            $failed !== null => ['status' => 'failed', 'error_code' => $failed->error_code,
                'failure' => ['failed_step' => $failed->name, 'stderr_recorded' => false]],
            $run->succeeded() => ['status' => 'succeeded', 'error_code' => null],
            default => ['status' => 'cancelled', 'error_code' => 'operation.cancelled'],
        };
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    private static function admitted(Operation $operation): int
    {
        $input = $operation->input;
        $node = $operation->node_id ?? 0;
        $id = $input['id'] ?? null;
        if ($operation->kind !== 'schedule.enable' || $node < 1 || ($input['node_id'] ?? null) !== $node
            || ! is_string($id) || preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/D', $id) !== 1) {
            throw new EngineUnsupported('The Schedule intent is outside the signed timer contract.');
        }
        if (isset($input['production_control']) || isset($input['container'])) {
            throw new EngineUnsupported('A release or Instance container timer stays on Swamp.');
        }
        $schedule = Schedule::query()->find($id);
        if ($schedule === null || $schedule->trigger !== ScheduleTrigger::Node) {
            throw new EngineUnsupported('Only a retained host Node timer runs on the engine.');
        }

        return $node;
    }
}
