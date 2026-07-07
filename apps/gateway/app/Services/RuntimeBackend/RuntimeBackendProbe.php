<?php

declare(strict_types=1);

namespace App\Services\RuntimeBackend;

use App\Contracts\RemoteShell;
use App\Data\RuntimeBackend\RuntimeBackendProbeResult;
use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\RemoteShell\RemoteLocalExecutor;
use JsonException;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class RuntimeBackendProbe
{
    public function __construct(
        private RemoteShell $_remoteShell,
        private ?RemoteLocalExecutor $localExecutor = null,
    ) {}

    public function check(Node $node): RuntimeBackendProbeResult
    {
        $result = $this->localExecutor()->runInternal(
            node: $node,
            commandName: 'internal:runtime-backend:probe',
            arguments: [$this->provider($node)],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'runtime-backend.probe',
                ],
                'timeout' => 15,
            ],
        );

        $data = $this->successData($result->stdout);

        return new RuntimeBackendProbeResult(
            available: array_key_exists('available', $data) ? $data['available'] === true : $result->successful(),
            exitCode: is_int($data['exit_code'] ?? null) ? $data['exit_code'] : $result->exitCode,
            output: is_string($data['output'] ?? null) ? trim($data['output']) : trim($result->output()),
        );
    }

    public function remoteShell(): RemoteShell
    {
        return $this->_remoteShell;
    }

    private function provider(Node $node): string
    {
        if (NodeHostPaths::isMacosPlatform($node->platform)) {
            return 'launchd';
        }

        if ($this->usesDockerProvider($node)) {
            return 'docker';
        }

        return 'systemd';
    }

    private function localExecutor(): RemoteLocalExecutor
    {
        return $this->localExecutor ?? app(RemoteLocalExecutor::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function successData(string $output): array
    {
        try {
            /** @var mixed $payload */
            $payload = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($payload)) {
            return [];
        }

        if (! array_key_exists('success', $payload) || ! is_array($payload['success'])) {
            return [];
        }

        $success = $payload['success'];

        if (! array_key_exists('data', $success) || ! is_array($success['data'])) {
            return [];
        }

        $data = $success['data'];

        foreach (array_keys($data) as $key) {
            if (! is_string($key)) {
                return [];
            }
        }

        /** @var array<string, mixed> $data */
        return $data;
    }

    private function usesDockerProvider(Node $node): bool
    {
        $platform = strtolower((string) $node->platform);

        return str_starts_with($platform, 'macos') || $platform === 'darwin';
    }
}
