<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/** Explicit schedule family Doctor issue classifications. */
final class ScheduleDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::genuine('schedule.heartbeat_stale', 'schedule', 'restore_schedule_heartbeat_stale'),
            self::genuine('schedule.lock_stuck', 'schedule', 'restore_schedule_lock_stuck'),
            self::invalid('schedule.record_incomplete', 'schedule'),
            self::blocked('schedule.remote_shell_probe_failed', 'schedule'),
            self::incident('schedule.run_stuck', 'schedule'),
            self::blocked('schedule.runtime_backend_unavailable', 'schedule'),
            self::genuine(
                'schedule.runtime_hibernator_image_mismatch',
                'schedule',
                'restore_schedule_runtime_hibernator_image_mismatch',
            ),
            self::genuine(
                'schedule.runtime_hibernator_missing',
                'schedule',
                'restore_schedule_runtime_hibernator_missing',
            ),
            self::genuine(
                'schedule.runtime_hibernator_replicas_mismatch',
                'schedule',
                'restore_schedule_runtime_hibernator_replicas_mismatch',
            ),
            self::genuine(
                'schedule.runtime_hibernator_stopped',
                'schedule',
                'restore_schedule_runtime_hibernator_stopped',
            ),
            self::genuine('schedule.scheduler_image_mismatch', 'schedule', 'restore_schedule_scheduler_image_mismatch'),
            self::genuine('schedule.scheduler_missing', 'schedule', 'restore_schedule_scheduler_missing'),
            self::genuine(
                'schedule.scheduler_replicas_mismatch',
                'schedule',
                'restore_schedule_scheduler_replicas_mismatch',
            ),
            self::genuine('schedule.scheduler_stopped', 'schedule', 'restore_schedule_scheduler_stopped'),
            self::invalid('schedule.target_invalid', 'schedule'),
            self::blocked('schedule.target_unreachable', 'schedule'),
        ];
    }
}
