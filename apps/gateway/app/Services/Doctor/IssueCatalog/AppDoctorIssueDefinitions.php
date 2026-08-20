<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/**
 * Internal app-family Doctor issue classifications.
 *
 * Public payloads project these codes to instance.* via DoctorPublicVocabulary.
 */
final class AppDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::blocked('app.remote_shell_probe_failed', 'app'),
            self::blocked('app.runtime_config_probe_failed', 'app'),
            self::blocked('app.runtime_extensions_unverifiable', 'app'),
            // No safe AppsFixer restorer; report-only until a deterministic repair exists.
            self::incident('app.production_user_mismatch', 'app'),
            self::incident('app.production_user_missing', 'app'),
            self::genuine(
                'app.runtime_config_extra',
                'app',
                'restore_app_runtime_config_extra',
            ),
            self::genuine(
                'app.runtime_config_mismatch',
                'app',
                'restore_app_runtime_config_mismatch',
            ),
            self::genuine(
                'app.runtime_config_missing',
                'app',
                'restore_app_runtime_config_missing',
            ),
            self::genuine(
                'app.security.fs_permissions',
                'app',
                'restore_app_security_fs_permissions',
            ),
            self::genuine(
                'app.security.system_user',
                'app',
                'restore_app_security_system_user',
            ),
            self::incident('app.deployment_run_stuck', 'app'),
            self::incident('app.latest_deployment_failed', 'app'),
            self::incident('app.path_missing', 'app'),
            self::incident('app.path_unusable', 'app'),
            self::incident('app.php_version_unavailable', 'app'),
            self::incident('app.production_health_unhealthy', 'app'),
            self::incident('app.root_missing', 'app'),
            self::incident('app.runtime_extension_missing', 'app'),
            self::incident('app.security.runtime_container_isolation', 'app'),
            self::invalid('app.deployment_pipeline_invalid', 'app'),
            self::invalid('app.record_incomplete', 'app'),
            self::invalid('app.root_outside_path', 'app'),
            self::invalid('app.serving_node_invalid', 'app'),
            self::invalid('app.unregistered_path', 'app'),
        ];
    }
}
