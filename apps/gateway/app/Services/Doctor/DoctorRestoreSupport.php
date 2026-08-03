<?php

declare(strict_types=1);

namespace App\Services\Doctor;

/**
 * Single source of truth for deterministic Doctor restore dispatch.
 *
 * A code is restorable only when it appears here. Catalog annotation,
 * apply routing, and inventory protection all consume this map — labels
 * alone never imply restore support.
 */
final class DoctorRestoreSupport
{
    /**
     * @return array<string, string> code => restore_action id
     */
    public static function map(): array
    {
        /** @var array<string, string>|null $cache */
        static $cache = null;

        if (is_array($cache)) {
            return $cache;
        }

        $map = [
            // Node (NodesProbe::reconcile + DNS projection + updates)
            'node.managed_agent_intent_invalid' => 'restore_node_managed_agent_intent_invalid',
            'node.agent_expectation_stale' => 'restore_node_agent_expectation_stale',
            'node.wireguard_peer_missing' => 'restore_node_wireguard_peer_missing',
            'node.wireguard_address_mismatch' => 'restore_node_wireguard_address_mismatch',
            'node.gateway_runtime_unready' => 'restore_node_gateway_runtime_unready',
            'node.access_grant_invalid' => 'restore_node_access_grant_invalid',
            'node.role_convergence_failed' => 'restore_node_role_convergence_failed',
            'node.role_baseline_mismatch' => 'restore_node_role_baseline_mismatch',
            'node.websocket.backend_cert_missing' => 'restore_node_websocket_backend_cert_missing',
            'node.websocket.bind_public_interface' => 'restore_node_websocket_bind_public_interface',
            'node.security.sshd_config' => 'restore_node_security_sshd_config',
            'node.security.sshd_listen' => 'restore_node_security_sshd_listen',
            'node.security.public_ssh_deny' => 'restore_node_security_public_ssh_deny',
            'node.security.sysctl' => 'restore_node_security_sysctl',
            'node.security.home_perms' => 'restore_node_security_home_perms',
            'node.dns_mapping_mismatch' => 'restore_node_dns_mapping_mismatch',
            'node.updates' => 'restore_node_updates',
            'node.updates_config_missing' => 'restore_node_updates',
            'node.updates_config_mismatch' => 'restore_node_updates',
            'node.updates_dry_run_failed' => 'restore_node_updates',
            'node.updates_last_run_failed' => 'restore_node_updates',
            'node.updates_unverifiable' => 'restore_node_updates',

            // Schedule gateway daemons (SchedulesFixer::fixGateway, no schedule_key)
            'schedule.lock_stuck' => 'restore_schedule_lock_stuck',
            'schedule.scheduler_missing' => 'restore_schedule_scheduler_missing',
            'schedule.scheduler_stopped' => 'restore_schedule_scheduler_stopped',
            'schedule.scheduler_image_mismatch' => 'restore_schedule_scheduler_image_mismatch',
            'schedule.scheduler_replicas_mismatch' => 'restore_schedule_scheduler_replicas_mismatch',
            'schedule.runtime_hibernator_missing' => 'restore_schedule_runtime_hibernator_missing',
            'schedule.runtime_hibernator_stopped' => 'restore_schedule_runtime_hibernator_stopped',
            'schedule.runtime_hibernator_image_mismatch' => 'restore_schedule_runtime_hibernator_image_mismatch',
            'schedule.runtime_hibernator_replicas_mismatch' => 'restore_schedule_runtime_hibernator_replicas_mismatch',

            // Process
            'process.runtime_unit_extra' => 'restore_process_runtime_unit_extra',
            'process.runtime_unit_unrenderable' => 'restore_process_runtime_unit_unrenderable',
            'process.event_notifier_missing' => 'restore_process_event_notifier_missing',
            'process.event_notifier_mismatch' => 'restore_process_event_notifier_mismatch',
            'process.runtime_unit_missing' => 'restore_process_runtime_unit_missing',
            'process.runtime_unit_mismatch' => 'restore_process_runtime_unit_mismatch',
            'process.runtime_unit_down' => 'restore_process_runtime_unit_down',
            'process.restart_policy_mismatch' => 'restore_process_restart_policy_mismatch',
            'process.runtime_environment_mismatch' => 'restore_process_runtime_environment_mismatch',

            // App / instance (internal + public)
            'app.runtime_config_extra' => 'restore_app_runtime_config_extra',
            'app.runtime_config_missing' => 'restore_app_runtime_config_missing',
            'app.runtime_config_mismatch' => 'restore_app_runtime_config_mismatch',
            'app.security.system_user' => 'restore_app_security_system_user',
            'app.security.fs_permissions' => 'restore_app_security_fs_permissions',
            'app.production_user_missing' => 'restore_app_production_user_missing',
            'app.production_user_mismatch' => 'restore_app_production_user_mismatch',
            'instance.runtime_config_extra' => 'restore_app_runtime_config_extra',
            'instance.runtime_config_missing' => 'restore_app_runtime_config_missing',
            'instance.runtime_config_mismatch' => 'restore_app_runtime_config_mismatch',
            'instance.security.system_user' => 'restore_app_security_system_user',
            'instance.security.fs_permissions' => 'restore_app_security_fs_permissions',
            'instance.production_user_missing' => 'restore_app_production_user_missing',
            'instance.production_user_mismatch' => 'restore_app_production_user_mismatch',

            // Firewall
            'firewall_rule.rule_missing' => 'restore_firewall_rule_rule_missing',
            'firewall_rule.rule_mismatch' => 'restore_firewall_rule_rule_mismatch',

            // Database
            'database_connection.env_missing' => 'restore_database_connection_env_missing',
            'database_connection.env_mismatch' => 'restore_database_connection_env_mismatch',
            'database_connection.target_missing' => 'restore_database_connection_target_missing',

            // DNS tool runtime
            'tool.dns_container_missing' => 'restore_tool_dns_container_missing',
            'tool.dns_port_not_listening' => 'restore_tool_dns_port_not_listening',
            'tool.dns_base_config_mismatch' => 'restore_tool_dns_base_config_mismatch',
            'tool.dns_client_dns_drift' => 'restore_tool_dns_client_dns_drift',
            'tool.dns_forwarding_missing' => 'restore_tool_dns_forwarding_missing',

            // Tool (via ToolsFixer when tool row present)
            'tool.capability_missing' => 'restore_tool_capability_missing',
            'tool.version_mismatch' => 'restore_tool_version_mismatch',
            'tool.config_missing' => 'restore_tool_config_missing',
            'tool.config_mismatch' => 'restore_tool_config_mismatch',
            'tool.credentials_missing' => 'restore_tool_credentials_missing',
            'tool.credentials_mismatch' => 'restore_tool_credentials_mismatch',
            'tool.container_missing' => 'restore_tool_container_missing',
            'tool.container_not_running' => 'restore_tool_container_not_running',
            'tool.container_spec_mismatch' => 'restore_tool_container_spec_mismatch',
            'tool.php_cli_coverage_missing' => 'restore_tool_php_cli_coverage_missing',
            'tool.agent_user_missing' => 'restore_tool_agent_user_missing',
            'tool.agent_credentials_missing' => 'restore_tool_agent_credentials_missing',
            'tool.seaweedfs.credentials_missing' => 'restore_tool_seaweedfs_credentials_missing',

            // Proxy (ProxyRouteFixer + DNS + extras)
            'proxy.route_missing' => 'restore_proxy_route_missing',
            'proxy.route_mismatch' => 'restore_proxy_route_mismatch',
            'proxy.route_extra' => 'restore_proxy_route_extra',
            'proxy.public_route_missing' => 'restore_proxy_public_route_missing',
            'proxy.public_route_mismatch' => 'restore_proxy_public_route_mismatch',
            'proxy.router_route_missing' => 'restore_proxy_router_route_missing',
            'proxy.router_route_mismatch' => 'restore_proxy_router_route_mismatch',
            'proxy.backend_route_missing' => 'restore_proxy_backend_route_missing',
            'proxy.backend_route_mismatch' => 'restore_proxy_backend_route_mismatch',
            'proxy.tls_missing' => 'restore_proxy_tls_missing',
            'proxy.tls_mismatch' => 'restore_proxy_tls_mismatch',
            'proxy.enactment_incomplete' => 'restore_proxy_enactment_incomplete',
            'proxy.caddy_container_missing' => 'restore_proxy_caddy_container_missing',
            'proxy.caddy_container_down' => 'restore_proxy_caddy_container_down',
            'proxy.caddy_container_detached' => 'restore_proxy_caddy_container_detached',
            'proxy.global_config_missing' => 'restore_proxy_global_config_missing',
            'proxy.global_config_mismatch' => 'restore_proxy_global_config_mismatch',
            'proxy.dns_mapping_mismatch' => 'restore_proxy_dns_mapping_mismatch',
            'proxy.agent_tool_route_missing' => 'restore_proxy_agent_tool_route_missing',
            'proxy.agent_tool_route_mismatch' => 'restore_proxy_agent_tool_route_mismatch',
            'proxy.websocket.router_route_missing' => 'restore_proxy_websocket_router_route_missing',
            'proxy.websocket.public_route_missing' => 'restore_proxy_websocket_public_route_missing',
            'proxy.websocket.router_route_orphaned' => 'restore_proxy_websocket_router_route_orphaned',
            'proxy.s3.router_route_missing' => 'restore_proxy_s3_router_route_missing',
            'proxy.s3.router_backend_invalid' => 'restore_proxy_s3_router_backend_invalid',
            'proxy.s3.public_route_missing' => 'restore_proxy_s3_public_route_missing',
            'proxy.s3.router_route_orphaned' => 'restore_proxy_s3_router_route_orphaned',
            'proxy.analytics.router_route_missing' => 'restore_proxy_analytics_router_route_missing',
            'proxy.analytics.router_route_orphaned' => 'restore_proxy_analytics_router_route_orphaned',
            'proxy.analytics.public_route_missing' => 'restore_proxy_analytics_public_route_missing',
        ];

        $cache = $map;

        return $map;
    }

    public static function supports(string $code): bool
    {
        return array_key_exists($code, self::map());
    }

    public static function actionId(string $code): ?string
    {
        return self::map()[$code] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_keys(self::map());
    }

    /**
     * Gateway-scoped schedule keys that fixGateway handles without a schedule row.
     *
     * @return list<string>
     */
    public static function scheduleGatewayCodes(): array
    {
        return [
            'schedule.lock_stuck',
            'schedule.scheduler_missing',
            'schedule.scheduler_stopped',
            'schedule.scheduler_image_mismatch',
            'schedule.scheduler_replicas_mismatch',
            'schedule.runtime_hibernator_missing',
            'schedule.runtime_hibernator_stopped',
            'schedule.runtime_hibernator_image_mismatch',
            'schedule.runtime_hibernator_replicas_mismatch',
        ];
    }
}
