<?php

declare(strict_types=1);

namespace App\Services\Operations;

use JsonException;

final readonly class LocalFleetUpdateInstallCliEnvironment
{
    /**
     * @return array<string, string>
     *
     * @throws JsonException
     */
    public function forPayload(LocalFleetUpdateInstallCliPayload $payload, string|false $path): array
    {
        $agentArtifact = $payload->agentArtifact;
        $agentService = $payload->agentService;

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
            'ORBIT_ROLE_IMAGES_JSON' => json_encode($payload->roleImages, JSON_THROW_ON_ERROR),
            'ORBIT_ROLE_IMAGE_ARTIFACTS_JSON' => json_encode(
                array_map(
                    static fn (LocalFleetUpdateInstallRoleImageArtifactPayload $artifact): array => [
                        'image' => $artifact->image,
                        'url' => $artifact->artifactUrl,
                        'sha256' => strtolower($artifact->sha256),
                    ],
                    $payload->roleImageArtifacts,
                ),
                JSON_THROW_ON_ERROR,
            ),
        ];
    }

    private function environmentString(string $key): string
    {
        $value = getenv($key);

        return is_string($value) ? $value : '';
    }
}
