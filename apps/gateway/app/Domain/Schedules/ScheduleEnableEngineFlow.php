<?php

declare(strict_types=1);

namespace App\Domain\Schedules;

use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineTargetFence;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\ReceiptBoundDefinition;
use App\Domain\Engine\StepSpec;
use App\Models\Operation;
use App\Models\Schedule;
use App\Support\ValidatedData;
use Illuminate\Database\Eloquent\Builder;
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
final readonly class ScheduleEnableEngineFlow implements ReceiptBoundDefinition
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

    /** The Schedule's own lock: its UUID, so the owner's other Schedules stay free. */
    public function lockKey(Operation $operation): string
    {
        $id = ValidatedData::string($operation->input['id']);
        EngineTargetFence::alone($operation, static fn (Builder $query) => $query->where('kind', 'like', 'schedule.%')->where('input->id', $id));

        return EngineTargetFence::scheduleKey($id);
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

    /**
     * The public result is the one Swamp's timer steps give for the same input: a failed step is `operation.native_failed`,
     * named by the operation-schedule-control step its phase stands for (`timer`, `check_timer`, `Check the timer`), and a
     * cancel has no error code. The primitive's own code stays on the engine step and in the failure's detail.
     */
    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        $data = match (true) {
            $failed !== null => ['status' => 'failed', 'error_code' => 'operation.native_failed',
                'failure' => ['failed_step' => match ($failed->error_code) {
                    'schedule.state_mismatch' => 'Check the timer',
                    'schedule.read_failed' => 'check_timer',
                    default => 'timer',
                }, 'detail' => self::Action.' '.$failed->error_code, 'stderr_recorded' => false]],
            $run->succeeded() => ['status' => 'succeeded', 'error_code' => null],
            default => ['status' => 'cancelled', 'error_code' => null],
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
