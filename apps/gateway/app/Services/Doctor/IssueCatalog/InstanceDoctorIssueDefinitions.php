<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/** Explicit instance family Doctor issue classifications. */
final class InstanceDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::invalid('instance.deployment_pipeline_invalid', 'instance'),
            self::incident('instance.deployment_run_stuck', 'instance'),
            self::incident('instance.latest_deployment_failed', 'instance'),
            self::incident('instance.path_missing', 'instance'),
            self::incident('instance.path_unusable', 'instance'),
            self::incident('instance.php_version_unavailable', 'instance'),
            self::incident('instance.production_health_unhealthy', 'instance'),
            // No safe AppsFixer restorer; report-only until a deterministic repair exists.
            self::incident('instance.production_user_mismatch', 'instance'),
            self::incident('instance.production_user_missing', 'instance'),
            self::invalid('instance.record_incomplete', 'instance'),
            self::incident('instance.root_missing', 'instance'),
            self::invalid('instance.root_outside_path', 'instance'),
            self::genuine(
                'instance.runtime_config_extra',
                'instance',
                'restore_app_runtime_config_extra',
            ),
            self::genuine(
                'instance.runtime_config_mismatch',
                'instance',
                'restore_app_runtime_config_mismatch',
            ),
            self::genuine(
                'instance.runtime_config_missing',
                'instance',
                'restore_app_runtime_config_missing',
            ),
            self::blocked('instance.runtime_config_probe_failed', 'instance'),
            self::incident('instance.runtime_extension_missing', 'instance'),
            self::blocked('instance.runtime_extensions_unverifiable', 'instance'),
            self::genuine(
                'instance.security.fs_permissions',
                'instance',
                'restore_app_security_fs_permissions',
            ),
            self::incident('instance.security.runtime_container_isolation', 'instance'),
            self::genuine(
                'instance.security.system_user',
                'instance',
                'restore_app_security_system_user',
            ),
            self::invalid('instance.serving_node_invalid', 'instance'),
            self::invalid('instance.unregistered_path', 'instance'),
        ];
    }
}
