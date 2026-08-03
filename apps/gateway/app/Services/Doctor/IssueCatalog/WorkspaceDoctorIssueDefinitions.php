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
            self::genuine('workspace.artifact_extra', 'workspace', 'restore_workspace_artifact_extra'),
            self::invalid('workspace.app_instance_invalid', 'workspace'),
            self::invalid('workspace.instance_invalid', 'workspace'),
            self::invalid('workspace.parent_project_invalid', 'workspace'),
            self::genuine('workspace.path_missing', 'workspace', 'restore_workspace_path_missing'),
            self::invalid('workspace.path_outside_policy', 'workspace'),
            self::incident('workspace.path_unusable', 'workspace'),
            self::invalid('workspace.php_hint_unsupported', 'workspace'),
            self::blocked('workspace.php_version_unavailable', 'workspace'),
            self::invalid('workspace.record_incomplete', 'workspace'),
            self::blocked('workspace.remote_shell_probe_failed', 'workspace'),
            self::genuine('workspace.runtime_config_mismatch', 'workspace', 'restore_workspace_runtime_config_mismatch'),
            self::genuine('workspace.runtime_config_missing', 'workspace', 'restore_workspace_runtime_config_missing'),
            self::genuine('workspace.security.fs_permissions', 'workspace', 'restore_workspace_security_fs_permissions'),
            self::genuine('workspace.security.system_user', 'workspace', 'restore_workspace_security_system_user'),
            self::invalid('workspace.unregistered_path', 'workspace'),
            self::invalid('workspace.unsupported_for_production', 'workspace'),
        ];
    }
}
