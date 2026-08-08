<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/** Explicit tool family Doctor issue classifications. */
final class ToolDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::blocked('tool.agent_consumer_url_unreachable', 'tool'),
            self::genuine(
                'tool.agent_credentials_missing',
                'tool',
                'restore_tool_agent_credentials_missing',
            ),
            self::blocked('tool.agent_orbit_cli_inaccessible', 'tool'),
            self::blocked('tool.agent_runtime_probe_failed', 'tool'),
            self::genuine(
                'tool.agent_user_missing',
                'tool',
                'restore_tool_agent_user_missing',
            ),
            self::genuine(
                'tool.capability_missing',
                'tool',
                'restore_tool_capability_missing',
            ),
            self::genuine(
                'tool.config_mismatch',
                'tool',
                'restore_tool_config_mismatch',
            ),
            self::genuine(
                'tool.config_missing',
                'tool',
                'restore_tool_config_missing',
            ),
            self::blocked('tool.config_probe_failed', 'tool'),
            self::genuine(
                'tool.container_missing',
                'tool',
                'restore_tool_container_missing',
            ),
            self::genuine(
                'tool.container_not_running',
                'tool',
                'restore_tool_container_not_running',
            ),
            self::genuine(
                'tool.container_spec_mismatch',
                'tool',
                'restore_tool_container_spec_mismatch',
            ),
            self::genuine(
                'tool.credentials_mismatch',
                'tool',
                'restore_tool_credentials_mismatch',
            ),
            self::genuine(
                'tool.credentials_missing',
                'tool',
                'restore_tool_credentials_missing',
            ),
            self::blocked('tool.credentials_probe_failed', 'tool'),
            self::invalid('tool.definition_missing', 'tool'),
            self::blocked('tool.docker_provider_unreachable', 'tool'),
            self::genuine(
                'tool.dns_base_config_mismatch',
                'tool',
                'restore_tool_dns_base_config_mismatch',
            ),
            self::genuine(
                'tool.dns_client_dns_drift',
                'tool',
                'restore_tool_dns_client_dns_drift',
            ),
            self::genuine(
                'tool.dns_container_missing',
                'tool',
                'restore_tool_dns_container_missing',
            ),
            self::genuine(
                'tool.dns_forwarding_missing',
                'tool',
                'restore_tool_dns_forwarding_missing',
            ),
            self::genuine(
                'tool.dns_port_not_listening',
                'tool',
                'restore_tool_dns_port_not_listening',
            ),
            self::invalid('tool.node_invalid', 'tool'),
            self::genuine(
                'tool.php_cli_coverage_missing',
                'tool',
                'restore_tool_php_cli_coverage_missing',
            ),
            self::invalid('tool.record_incomplete', 'tool'),
            self::blocked('tool.remote_shell_probe_failed', 'tool'),
            // Rotating an external provider secret is operator work; report-only
            // like the seaweedfs credential findings below.
            self::incident('tool.cloudflare.credentials_missing', 'tool'),
            self::incident('tool.cloudflare.token_rejected', 'tool'),
            self::blocked('tool.cloudflare.credentials_probe_failed', 'tool'),
            // ToolsFixer has no seaweedfs credential restorer; operator must re-run s3 configure.
            self::incident('tool.seaweedfs.credentials_missing', 'tool'),
            self::invalid('tool.seaweedfs.row_missing', 'tool'),
            self::invalid('tool.unregistered_capability', 'tool'),
            self::invalid('tool.unsupported_on_node', 'tool'),
            self::genuine(
                'tool.version_mismatch',
                'tool',
                'restore_tool_version_mismatch',
            ),
            self::blocked('tool.websocket.reverb_unavailable', 'tool'),
            self::blocked('tool.websocket.valkey_unavailable', 'tool'),
        ];
    }
}
