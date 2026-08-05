<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/** Explicit workspace family Doctor issue classifications. */
final class WorkspaceDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::incident('workspace.artifact_extra', 'workspace'),
            self::invalid('workspace.app_instance_invalid', 'workspace'),
            self::invalid('workspace.instance_invalid', 'workspace'),
            self::invalid('workspace.parent_project_invalid', 'workspace'),
            self::incident('workspace.path_missing', 'workspace'),
            self::invalid('workspace.path_outside_policy', 'workspace'),
            self::incident('workspace.path_unusable', 'workspace'),
            self::invalid('workspace.php_hint_unsupported', 'workspace'),
            self::blocked('workspace.php_version_unavailable', 'workspace'),
            self::invalid('workspace.record_incomplete', 'workspace'),
            self::blocked('workspace.remote_shell_probe_failed', 'workspace'),
            self::incident('workspace.runtime_config_mismatch', 'workspace'),
            self::incident('workspace.runtime_config_missing', 'workspace'),
            self::incident('workspace.security.fs_permissions', 'workspace'),
            self::incident('workspace.security.system_user', 'workspace'),
            self::invalid('workspace.unregistered_path', 'workspace'),
            self::invalid('workspace.unsupported_for_production', 'workspace'),
        ];
    }
}
