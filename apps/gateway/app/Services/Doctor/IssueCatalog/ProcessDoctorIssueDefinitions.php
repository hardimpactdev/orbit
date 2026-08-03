<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/** Explicit process family Doctor issue classifications. */
final class ProcessDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::genuine(
                'process.event_notifier_mismatch',
                'process',
                'restore_process_event_notifier_mismatch',
            ),
            self::genuine(
                'process.event_notifier_missing',
                'process',
                'restore_process_event_notifier_missing',
            ),
            self::invalid('process.owner_app_invalid', 'process'),
            self::invalid('process.owner_node_invalid', 'process'),
            self::invalid('process.record_incomplete', 'process'),
            self::blocked('process.remote_shell_probe_failed', 'process'),
            self::genuine(
                'process.restart_policy_mismatch',
                'process',
                'restore_process_restart_policy_mismatch',
            ),
            self::blocked('process.runtime_backend_unavailable', 'process'),
            self::invalid('process.runtime_context_unresolved', 'process'),
            self::genuine(
                'process.runtime_environment_mismatch',
                'process',
                'restore_process_runtime_environment_mismatch',
            ),
            self::genuine(
                'process.runtime_unit_down',
                'process',
                'restore_process_runtime_unit_down',
            ),
            self::genuine(
                'process.runtime_unit_extra',
                'process',
                'restore_process_runtime_unit_extra',
            ),
            self::genuine(
                'process.runtime_unit_mismatch',
                'process',
                'restore_process_runtime_unit_mismatch',
            ),
            self::genuine(
                'process.runtime_unit_missing',
                'process',
                'restore_process_runtime_unit_missing',
            ),
            self::incident('process.runtime_unit_unloaded', 'process'),
            self::genuine(
                'process.runtime_unit_unrenderable',
                'process',
                'restore_process_runtime_unit_unrenderable',
            ),
            self::incident('process.wireguard_self_route_unavailable', 'process'),
        ];
    }
}
