<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;
use App\Data\Doctor\DriftEntry;
use App\Enums\DriftKind;
use App\Models\Node;
use App\Models\Schedule;
use App\Services\Schedules\SchedulesFixer;
use Throwable;

final readonly class DoctorScheduleRestorer
{
    public function __construct(
        private DoctorGatewayScheduleRestorer $gatewayScheduleRestorer,
        private SchedulesFixer $schedulesFixer,
        private DoctorScheduleNodeResolver $scheduleNodeResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>|null
     */
    public function apply(Node $node, string $key, array $detail, DoctorIssue $issue): ?array
    {
        $scheduleKey = is_string($detail['schedule_key'] ?? null) ? $detail['schedule_key'] : null;
        $schedule = $scheduleKey === null
            ? null
            : Schedule::query()->where('schedule_key', $scheduleKey)->first();

        if (in_array($key, SchedulesFixer::GatewayRestorableCodes, strict: true)) {
            return $this->gatewayScheduleRestorer->apply(
                $node,
                $key,
                $detail,
                $issue,
                $schedule instanceof Schedule ? $schedule : null,
            );
        }

        if (! $schedule instanceof Schedule) {
            return null;
        }

        return $this->restoreSchedule(
            $schedule,
            new DriftEntry(
                family: 'schedule',
                key: $key,
                kind: DriftKind::Divergent,
                summary: '',
                detail: $detail,
            ),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restoreSchedule(Schedule $schedule, DriftEntry $entry): ?array
    {
        try {
            return $this->schedulesFixer->fix($schedule, $entry);
        } catch (Throwable $exception) {
            return [
                'family' => $entry->family,
                'node' => $this->scheduleNodeResolver->nameFor($schedule),
                'code' => $entry->key,
                'key' => $entry->key,
                'mode' => 'restore',
                'status' => 'failed',
                'summary' => "Failed to fix {$entry->key}.",
                'details' => [
                    'error' => $exception->getMessage(),
                ],
            ];
        }
    }
}
