<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/** Explicit node family Doctor issue classifications. */
final class NodeDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::genuine(
                'node.access_grant_invalid',
                'node',
                'restore_node_access_grant_invalid',
            ),
            self::invalid('node.access_permission_invalid', 'node'),
            self::genuine(
                'node.agent_expectation_stale',
                'node',
                'restore_node_agent_expectation_stale',
            ),
            self::invalid('node.agent_ide_default_invalid', 'node'),
            self::blocked('node.bootstrap_network_policy_mismatch', 'node'),
            self::genuine(
                'node.dns_mapping_mismatch',
                'node',
                'restore_node_dns_mapping_mismatch',
            ),
            self::blocked('node.docker_runtime_unavailable', 'node'),
            self::blocked('node.gateway_api_unreachable', 'node'),
            self::blocked('node.gateway_ca_mismatch', 'node'),
            self::genuine(
                'node.gateway_runtime_unready',
                'node',
                'restore_node_gateway_runtime_unready',
            ),
            self::blocked('node.identity_unresolved', 'node'),
            self::invalid('node.local_default_invalid', 'node'),
            self::blocked('node.local_executor_probe_failed', 'node'),
            self::genuine(
                'node.managed_agent_intent_invalid',
                'node',
                'restore_node_managed_agent_intent_invalid',
            ),
            self::blocked('node.node_identity_artifact_missing', 'node'),
            self::invalid('node.platform_record_mismatch', 'node'),
            self::invalid('node.platform_unsupported', 'node'),
            self::invalid('node.record_incomplete', 'node'),
            self::blocked('node.remote_shell_probe_failed', 'node'),
            self::invalid('node.role_assignment_invalid', 'node'),
            self::invalid('node.role_assignment_missing', 'node'),
            self::genuine(
                'node.role_baseline_mismatch',
                'node',
                'restore_node_role_baseline_mismatch',
            ),
            self::invalid('node.role_conflict', 'node'),
            self::genuine(
                'node.role_convergence_failed',
                'node',
                'restore_node_role_convergence_failed',
            ),
            self::invalid('node.role_settings_invalid', 'node'),
            self::incident('node.runtime_container_missing', 'node'),
            self::incident('node.runtime_container_stopped', 'node'),
            self::blocked('node.runtime_missing', 'node'),
            self::incident('node.s3.wireguard_missing', 'node'),
            self::invalid('node.s3_data_path_invalid', 'node'),
            self::genuine(
                'node.security.home_perms',
                'node',
                'restore_node_security_home_perms',
            ),
            self::blocked('node.security.posture_probe_failed', 'node'),
            self::genuine(
                'node.security.public_ssh_deny',
                'node',
                'restore_node_security_public_ssh_deny',
            ),
            self::incident('node.security.runtime_user', 'node'),
            self::genuine(
                'node.security.sshd_config',
                'node',
                'restore_node_security_sshd_config',
            ),
            self::genuine(
                'node.security.sshd_listen',
                'node',
                'restore_node_security_sshd_listen',
            ),
            self::genuine(
                'node.security.sysctl',
                'node',
                'restore_node_security_sysctl',
            ),
            self::blocked('node.transport_unreachable', 'node'),
            self::genuine(
                'node.updates',
                'node',
                'restore_node_updates',
            ),
            self::genuine(
                'node.updates_config_mismatch',
                'node',
                'restore_node_updates',
            ),
            self::genuine(
                'node.updates_config_missing',
                'node',
                'restore_node_updates',
            ),
            self::genuine(
                'node.updates_dry_run_failed',
                'node',
                'restore_node_updates',
            ),
            self::genuine(
                'node.updates_last_run_failed',
                'node',
                'restore_node_updates',
            ),
            self::incident('node.updates_reboot_required', 'node'),
            self::genuine(
                'node.updates_unverifiable',
                'node',
                'restore_node_updates',
            ),
            self::genuine(
                'node.websocket.backend_cert_missing',
                'node',
                'restore_node_websocket_backend_cert_missing',
            ),
            self::genuine(
                'node.websocket.bind_public_interface',
                'node',
                'restore_node_websocket_bind_public_interface',
            ),
            self::genuine(
                'node.wireguard_address_mismatch',
                'node',
                'restore_node_wireguard_address_mismatch',
            ),
            self::invalid('node.wireguard_peer_extra', 'node'),
            self::genuine(
                'node.wireguard_peer_missing',
                'node',
                'restore_node_wireguard_peer_missing',
            ),
        ];
    }
}
