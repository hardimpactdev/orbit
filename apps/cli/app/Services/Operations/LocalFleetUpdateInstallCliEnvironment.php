<?php

declare(strict_types=1);

namespace App\Services\Operations;

use Orbit\Core\Nodes\NodeSystemdServiceRenderer;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class LocalFleetUpdateInstallCliEnvironment
{
    public function __construct(
        private NodeSystemdServiceRenderer $systemdServices,
    ) {}

    /**
     * @return array<string, string>
     */
    public function forPayload(LocalFleetUpdateInstallCliPayload $payload, string|false $path): array
    {
        $agentArtifact = $payload->agentArtifact;
        $agentService = $payload->agentService;
        $agentUnit = $agentService instanceof LocalFleetUpdateInstallAgentServicePayload
            ? $this->systemdServices->agentUnit(
                user: $agentService->user,
                agentBinary: $agentService->execStart,
                orbitBinary: $payload->binPath,
                configPath: $agentService->configPath,
                httpBind: $agentService->httpBind,
            )
            : '';

        return [
            'PATH' => is_string($path) ? $path : '',
            'ORBIT_CLI_ARTIFACT_URL' => $payload->artifactUrl,
            'ORBIT_CLI_SHA256' => strtolower($payload->sha256),
            'ORBIT_INSTALL_PATH' => $payload->installRoot,
            'ORBIT_BIN_PATH' => $payload->binPath,
            'ORBIT_SHARED_BINARY_PATH' => $payload->sharedBinaryPath ?? '',
            'ORBIT_AGENT_ARTIFACT_URL' => $agentArtifact->artifactUrl ?? '',
            'ORBIT_AGENT_SHA256' => strtolower($agentArtifact->sha256 ?? ''),
            'ORBIT_AGENT_BIN_PATH' => $agentArtifact->binPath ?? '',
            'ORBIT_AGENT_LAUNCHD_LABEL' => $this->environmentString('ORBIT_AGENT_LAUNCHD_LABEL'),
            'ORBIT_AGENT_LAUNCHCTL_BIN' => $this->environmentString('ORBIT_AGENT_LAUNCHCTL_BIN'),
            'ORBIT_AGENT_SERVICE_UNIT_NAME' => $agentService->unitName ?? '',
            'ORBIT_AGENT_SERVICE_EXEC_START' => $agentService->execStart ?? '',
            'ORBIT_AGENT_SERVICE_CONFIG_PATH' => $agentService->configPath ?? '',
            'ORBIT_AGENT_SERVICE_CONFIG_BASE64' => $agentService instanceof LocalFleetUpdateInstallAgentServicePayload
                ? base64_encode($agentService->config)
                : '',
            'ORBIT_AGENT_SERVICE_CA_PATH' => $agentService->caPath ?? '',
            'ORBIT_AGENT_SERVICE_CA_BASE64' => $agentService instanceof LocalFleetUpdateInstallAgentServicePayload
                ? base64_encode($agentService->caPem)
                : '',
            'ORBIT_AGENT_SERVICE_HTTP_BIND' => $agentService->httpBind ?? '',
            'ORBIT_AGENT_SERVICE_USER' => $agentService->user ?? '',
            'ORBIT_AGENT_SYSTEMD_UNIT_BASE64' => $agentUnit === '' ? '' : base64_encode($agentUnit),
            'ORBIT_RUNTIME_BOOT_SCRIPT_BASE64' => base64_encode($this->systemdServices->runtimeBootScript()),
            'ORBIT_RUNTIME_BOOT_UNIT_BASE64' => base64_encode($this->systemdServices->runtimeBootUnit()),
            'ORBIT_RUNTIME_BOOT_SCRIPT_PATH' => NodeSystemdServiceRenderer::RuntimeBootScriptPath,
            'ORBIT_RUNTIME_BOOT_UNIT_NAME' => NodeSystemdServiceRenderer::RuntimeBootUnitName,
            'ORBIT_RUNTIME_BOOT_UNIT_PATH' => NodeSystemdServiceRenderer::RuntimeBootUnitPath,
            'ORBIT_ROLE_IMAGES_LINES' => implode(
                "\n",
                array_map(
                    base64_encode(...),
                    $payload->roleImages,
                ),
            ),
            'ORBIT_ROLE_IMAGE_ARTIFACTS_LINES' => implode(
                "\n",
                array_map(
                    static fn (LocalFleetUpdateInstallRoleImageArtifactPayload $artifact): string => implode(' ', [
                        base64_encode($artifact->image),
                        base64_encode($artifact->artifactUrl),
                        strtolower($artifact->sha256),
                    ]),
                    $payload->roleImageArtifacts,
                ),
            ),
            'ORBIT_ROLE_IMAGE_ALIASES_LINES' => implode(
                "\n",
                array_map(
                    static fn (LocalFleetUpdateInstallRoleImageAliasPayload $alias): string => implode(' ', [
                        base64_encode($alias->source),
                        base64_encode($alias->target),
                    ]),
                    $payload->roleImageAliases,
                ),
            ),
        ];
    }

    private function environmentString(string $key): string
    {
        $value = getenv($key);

        return is_string($value) ? $value : '';
    }
}
