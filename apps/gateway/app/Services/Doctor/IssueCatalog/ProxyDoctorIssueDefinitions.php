<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

/** Explicit proxy family Doctor issue classifications. */
final class ProxyDoctorIssueDefinitions implements DoctorIssueDefinitionProvider
{
    use DefinesDoctorIssues;

    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array
    {
        return [
            self::invalid('proxy.agent_tool_route_conflict', 'proxy'),
            self::genuine('proxy.agent_tool_route_mismatch', 'proxy', 'restore_proxy_agent_tool_route_mismatch'),
            self::invalid('proxy.backend_node_invalid', 'proxy'),
            self::genuine('proxy.agent_tool_route_missing', 'proxy', 'restore_proxy_agent_tool_route_missing'),
            self::genuine('proxy.analytics.public_route_missing', 'proxy', 'restore_proxy_analytics_public_route_missing'),
            self::genuine('proxy.analytics.router_route_missing', 'proxy', 'restore_proxy_analytics_router_route_missing'),
            self::genuine('proxy.analytics.router_route_orphaned', 'proxy', 'restore_proxy_analytics_router_route_orphaned'),
            self::genuine('proxy.backend_route_mismatch', 'proxy', 'restore_proxy_backend_route_mismatch'),
            self::genuine('proxy.backend_route_missing', 'proxy', 'restore_proxy_backend_route_missing'),
            self::genuine('proxy.caddy_container_detached', 'proxy', 'restore_proxy_caddy_container_detached'),
            self::genuine('proxy.caddy_container_down', 'proxy', 'restore_proxy_caddy_container_down'),
            self::genuine('proxy.caddy_container_missing', 'proxy', 'restore_proxy_caddy_container_missing'),
            self::genuine('proxy.dns_mapping_mismatch', 'proxy', 'restore_proxy_dns_mapping_mismatch'),
            self::blocked('proxy.docker_runtime_unavailable', 'proxy'),
            self::invalid('proxy.domain_conflict', 'proxy'),
            self::genuine('proxy.enactment_incomplete', 'proxy', 'restore_proxy_enactment_incomplete'),
            self::genuine('proxy.global_config_mismatch', 'proxy', 'restore_proxy_global_config_mismatch'),
            self::genuine('proxy.global_config_missing', 'proxy', 'restore_proxy_global_config_missing'),
            self::invalid('proxy.node_invalid', 'proxy'),
            self::blocked('proxy.node_probe_failed', 'proxy'),
            self::invalid('proxy.owner_invalid', 'proxy'),
            self::genuine('proxy.public_route_mismatch', 'proxy', 'restore_proxy_public_route_mismatch'),
            self::genuine('proxy.public_route_missing', 'proxy', 'restore_proxy_public_route_missing'),
            self::invalid('proxy.record_incomplete', 'proxy'),
            self::blocked('proxy.remote_shell_probe_failed', 'proxy'),
            self::genuine('proxy.route_extra', 'proxy', 'restore_proxy_route_extra'),
            self::genuine('proxy.route_mismatch', 'proxy', 'restore_proxy_route_mismatch'),
            self::genuine('proxy.route_missing', 'proxy', 'restore_proxy_route_missing'),
            self::invalid('proxy.router_node_invalid', 'proxy'),
            self::genuine('proxy.router_route_mismatch', 'proxy', 'restore_proxy_router_route_mismatch'),
            self::genuine('proxy.router_route_missing', 'proxy', 'restore_proxy_router_route_missing'),
            self::blocked('proxy.runtime_unreachable', 'proxy'),
            self::genuine('proxy.s3.public_route_missing', 'proxy', 'restore_proxy_s3_public_route_missing'),
            self::genuine('proxy.s3.router_backend_invalid', 'proxy', 'restore_proxy_s3_router_backend_invalid'),
            self::genuine('proxy.s3.router_route_missing', 'proxy', 'restore_proxy_s3_router_route_missing'),
            self::genuine('proxy.s3.router_route_orphaned', 'proxy', 'restore_proxy_s3_router_route_orphaned'),
            self::genuine('proxy.tls_mismatch', 'proxy', 'restore_proxy_tls_mismatch'),
            self::genuine('proxy.tls_missing', 'proxy', 'restore_proxy_tls_missing'),
            self::genuine('proxy.websocket.public_route_missing', 'proxy', 'restore_proxy_websocket_public_route_missing'),
            self::genuine('proxy.websocket.router_route_missing', 'proxy', 'restore_proxy_websocket_router_route_missing'),
            self::genuine('proxy.websocket.router_route_orphaned', 'proxy', 'restore_proxy_websocket_router_route_orphaned'),
        ];
    }
}
