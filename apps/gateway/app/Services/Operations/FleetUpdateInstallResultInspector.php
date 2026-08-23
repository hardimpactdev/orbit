<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\RemoteShell\RemoteShellResult;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class FleetUpdateInstallResultInspector
{
    public function expectedAgentInstallWasConfirmed(RemoteShellResult $result, string $installPayloadJson): bool
    {
        if (! $this->expectsAgentInstall($installPayloadJson)) {
            return true;
        }

        return $this->installResultConfirmsAgentInstall($result);
    }

    public function expectsAgentInstall(string $installPayloadJson): bool
    {
        $payload = $this->jsonObject($installPayloadJson);

        return is_array($payload['agent_artifact'] ?? null);
    }

    public function requiresBootstrapCliStage(string $installPayloadJson): bool
    {
        $payload = $this->jsonObject($installPayloadJson);

        if (is_array($payload['agent_artifact'] ?? null) || is_array($payload['agent_service'] ?? null)) {
            return true;
        }

        return (
            $this->hasNonEmptyListField($payload, 'role_images')
            || $this->hasNonEmptyListField($payload, 'role_image_artifacts')
            || $this->hasNonEmptyListField($payload, 'role_image_aliases')
        );
    }

    public function cliOnlyInstallPayloadJson(string $installPayloadJson): string
    {
        $payload = $this->jsonObject($installPayloadJson);

        return json_encode([
            'artifact_url' => $payload['artifact_url'] ?? null,
            'sha256' => $payload['sha256'] ?? null,
            'install_root' => $payload['install_root'] ?? null,
            'bin_path' => $payload['bin_path'] ?? null,
            'shared_binary_path' => $payload['shared_binary_path'] ?? null,
            'agent_artifact' => null,
            'agent_service' => null,
            'desktop_artifact' => null,
            'pending_desktop_update' => null,
            'role_images' => [],
            'role_image_artifacts' => [],
            'role_image_aliases' => [],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function hasNonEmptyListField(array $payload, string $key): bool
    {
        return array_key_exists($key, $payload) && is_array($payload[$key]) && $payload[$key] !== [];
    }

    private function installResultConfirmsAgentInstall(RemoteShellResult $result): bool
    {
        $payload = $this->jsonObject($result->stdout);

        if (! array_key_exists('success', $payload) || ! is_array($payload['success'])) {
            return false;
        }

        if (! array_key_exists('data', $payload['success']) || ! is_array($payload['success']['data'])) {
            return false;
        }

        return ($payload['success']['data']['agent_installed'] ?? false) === true;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonObject(string $json): array
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        foreach (array_keys($decoded) as $key) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
