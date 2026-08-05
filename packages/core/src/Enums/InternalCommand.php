<?php

declare(strict_types=1);

namespace Orbit\Core\Enums;

/**
 * @mago-expect lint:too-many-enum-cases
 */
enum InternalCommand: string
{
    case AgentAclEnsure = 'internal:agent-acl:ensure';
    case AgentRuntimeProbe = 'internal:agent-runtime:probe';
    case AgentUserEnsure = 'internal:agent-user:ensure';
    case AppCacheClear = 'internal:app-cache:clear';
    case AppIntrospectProbe = 'internal:app-introspect:probe';
    case AppRuntimeConfigsProbe = 'internal:app-runtime-configs:probe';
    case AppRuntimeContainer = 'internal:app-runtime-container';
    case AppRuntimeContainersProbe = 'internal:app-runtime-containers:probe';
    case AppRuntimeExtensionsProbe = 'internal:app-runtime-extensions:probe';
    case AppSecurityRepair = 'internal:app-security:repair';
    case AppSetupStep = 'internal:app-setup-step';
    case AppSourceCreate = 'internal:app-source:create';
    case AppSourcePathProbe = 'internal:app-source-path:probe';
    case AppWorkerReadinessProbe = 'internal:app-worker-readiness:probe';
    case CaddyConfig = 'internal:caddy-config';
    case CodexAppConfig = 'internal:codex-app-config';
    case DatabaseAddUser = 'internal:database-add-user';
    case DatabaseQueryLocal = 'internal:database-query-local';
    case DeployRunStep = 'internal:deploy:run-step';
    case DoctorSelf = 'internal:doctor-self';
    case EnvFile = 'internal:env-file';
    case ExecutorVerify = 'internal:executor:verify';
    case FirewallRule = 'internal:firewall-rule';
    case FirewallRuleProbe = 'internal:firewall-rule:probe';
    case FleetUpdateInstallCli = 'internal:fleet-update:install-cli';
    case FleetUpdateVerify = 'internal:fleet-update:verify';
    case GatewayRuntimeBackendProbe = 'internal:gateway-runtime-backend:probe';
    case ManagedFile = 'internal:managed-file';
    case NodeSecurityPostureProbe = 'internal:node-security-posture:probe';
    case ProcessDockerContainer = 'internal:process-docker-container';
    case ProcessDockerSwarmService = 'internal:process-docker-swarm-service';
    case ProcessLaunchdService = 'internal:process-launchd-service';
    case ApplicationLog = 'internal:application-log';
    case ProcessLogs = 'internal:process-logs';
    case ProcessSystemdService = 'internal:process-systemd-service';
    case RuntimeBackendProbe = 'internal:runtime-backend:probe';
    case RuntimeDependencies = 'internal:runtime-dependencies';
    case S3RuntimeProbe = 'internal:s3-runtime:probe';
    case ScheduleRun = 'internal:schedule:run';
    case SecretFile = 'internal:secret-file';
    case ToolRunScript = 'internal:tool:run-script';
    case SiteCertificateInstall = 'internal:site-certificate:install';
    case SoloUpstreamRequest = 'internal:solo-upstream-request';
    case UnattendedUpgradesApply = 'internal:unattended-upgrades:apply';
    case UnattendedUpgradesProbe = 'internal:unattended-upgrades:probe';
    case WebsocketRuntime = 'internal:websocket-runtime';
    case WgEasyState = 'internal:wg-easy:state';
    case WireguardEndpointRotate = 'internal:wireguard-endpoint:rotate';
    case WireguardInterfacePublicKeyRead = 'internal:wireguard-interface-public-key:read';
    case WireguardSelfRoute = 'internal:wireguard-self-route';
    case WorkspaceSetupStep = 'internal:workspace-setup-step';
    case WorkspaceSourceCreate = 'internal:workspace-source:create';
}
