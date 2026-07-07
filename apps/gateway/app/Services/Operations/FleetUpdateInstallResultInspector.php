<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Data\RemoteShell\RemoteShellResult;
use Throwable;

final readonly class FleetUpdateInstallResultInspector
{
    public function shouldRetryAgentInstallAfterCliSelfUpdate(
        RemoteShellResult $result,
        string $installPayloadJson,
    ): bool {
        return (
            $result->successful()
            && $this->expectsAgentInstall($installPayloadJson)
            && ! $this->installResultConfirmsAgentInstall($result)
        );
    }

    public function expectedAgentInstallWasConfirmed(RemoteShellResult $result, string $installPayloadJson): bool
    {
        if (! $this->expectsAgentInstall($installPayloadJson)) {
            return true;
        }

        return $this->installResultConfirmsAgentInstall($result);
    }

    private function expectsAgentInstall(string $installPayloadJson): bool
    {
        $payload = $this->jsonObject($installPayloadJson);

        return is_array($payload['agent_artifact'] ?? null);
    }

    private function installResultConfirmsAgentInstall(RemoteShellResult $result): bool
    {
        $payload = $this->jsonObject($result->stdout);
        $success = $payload['success'] ?? null;
        $data = is_array($success) ? $success['data'] ?? null : null;

        return is_array($data) && ($data['agent_installed'] ?? false) === true;
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
