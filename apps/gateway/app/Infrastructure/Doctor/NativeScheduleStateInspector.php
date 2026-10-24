<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\ScheduleInspectionData;
use App\Domain\Doctor\ScheduleStateInspector;
use App\Domain\Schedules\ScheduleSpecification;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Schedule;
use Throwable;

final readonly class NativeScheduleStateInspector implements ScheduleStateInspector
{
    public function __construct(
        private ScheduleTargetResolver $targets,
        private ScheduleSpecification $specification,
        private AgentCommandRunner $agents,
    ) {}

    public function inspect(Schedule $schedule): ScheduleInspectionData
    {
        try {
            $target = $this->targets->forInspection($schedule);
            $result = $this->agents->run($target->node, 'doctor.inspect-schedule', $this->specification->input($schedule, $target));
            $values = [];
            foreach (['artifacts_present', 'permissions_match', 'specification_match', 'timer_state_match', 'calendar_match', 'execution_context_match', 'completion_callback_match'] as $key) {
                if (! is_bool($result[$key] ?? null)) {
                    throw new DoctorInspectionException;
                }
                $values[] = $result[$key];
            }

            return new ScheduleInspectionData(...$values);
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }

    public function orphanIds(Node $node, array $knownIds): array
    {
        try {
            $result = $this->agents->run($node, 'doctor.schedule-orphans', ['known_ids' => $knownIds]);
            $ids = $result['ids'] ?? null;
            if (! is_array($ids) || ! array_is_list($ids) || array_any($ids, static fn (mixed $id): bool => ! is_string($id))) {
                throw new DoctorInspectionException;
            }

            return $ids;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }
}
