<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;
use App\Services\RemoteShell\RemoteLocalExecutor;

final readonly class NodeWireGuardSelfRouteProbe
{
    public const string UnsupportedMessage = 'WireGuard self-route diagnostics are only supported on Linux.';

    public function __construct(
        private RemoteLocalExecutor $localExecutor,
        private WireGuardSelfRouteOutput $routeOutput,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     supported: bool,
     *     reason: string|null,
     *     message: string,
     *     platform: string|null,
     *     wireguard_address: string|null,
     *     command: string|null,
     *     exit_code: int|null,
     *     output: string
     * }
     */
    public function probe(Node $node): array
    {
        $platform = $this->normalizedPlatform($node);
        $address = trim((string) $node->wireguard_address);

        if ($address === '') {
            return $this->result(
                ok: false,
                supported: false,
                reason: 'wireguard_address_missing',
                message: 'WireGuard self-route diagnostics require a node WireGuard address.',
                platform: $platform,
                address: null,
            );
        }

        if ($this->isUnsupportedPlatform($platform)) {
            return $this->result(
                ok: false,
                supported: false,
                reason: 'unsupported_platform',
                message: self::UnsupportedMessage,
                platform: $platform,
                address: $address,
            );
        }

        $command = 'ip route get '.escapeshellarg($address);
        $result = $this->localExecutor->runInternal(
            node: $node,
            commandName: 'internal:wireguard-self-route',
            arguments: [$address],
            transportOptions: [
                'metadata' => [
                    'ORBIT_OPERATION_ID' => 'wireguard-self-route.probe',
                ],
                'strict' => true,
                'timeout' => 15,
            ],
        );
        $data = $this->routeOutput->data($result);
        $exitCode = $data['exit_code'] ?? null;
        $output = is_string($data['output'] ?? null) ? trim($data['output']) : trim($result->output());

        if (! $result->successful() || ! is_int($exitCode)) {
            return $this->result(
                ok: false,
                supported: true,
                reason: 'route_unverifiable',
                message: 'WireGuard self-route could not be inspected with ip route get.',
                platform: $platform,
                address: $address,
                command: $command,
                exitCode: $result->exitCode,
                output: $output,
            );
        }

        if ($exitCode !== 0) {
            return $this->result(
                ok: false,
                supported: true,
                reason: 'route_unverifiable',
                message: 'WireGuard self-route could not be inspected with ip route get.',
                platform: $platform,
                address: $address,
                command: $command,
                exitCode: $exitCode,
                output: $output,
            );
        }

        if ($this->routeOutput->hasLocalRoute($output, $address)) {
            return $this->result(
                ok: true,
                supported: true,
                reason: null,
                message: 'Linux node routes its own WireGuard address locally.',
                platform: $platform,
                address: $address,
                command: $command,
                exitCode: $exitCode,
                output: $output,
            );
        }

        return $this->result(
            ok: false,
            supported: true,
            reason: 'self_route_missing',
            message: 'Linux node does not route its own WireGuard address locally.',
            platform: $platform,
            address: $address,
            command: $command,
            exitCode: $exitCode,
            output: $output,
        );
    }

    private function normalizedPlatform(Node $node): ?string
    {
        $platform = trim((string) $node->platform);

        return $platform === '' ? null : strtolower($platform);
    }

    private function isUnsupportedPlatform(?string $platform): bool
    {
        if ($platform === null) {
            return false;
        }

        return (
            $platform === 'macos'
            || $platform === 'darwin'
            || str_starts_with($platform, 'macos_')
            || str_starts_with($platform, 'darwin_')
        );
    }

    /**
     * @return array{
     *     ok: bool,
     *     supported: bool,
     *     reason: string|null,
     *     message: string,
     *     platform: string|null,
     *     wireguard_address: string|null,
     *     command: string|null,
     *     exit_code: int|null,
     *     output: string
     * }
     */
    private function result(
        bool $ok,
        bool $supported,
        ?string $reason,
        string $message,
        ?string $platform,
        ?string $address,
        ?string $command = null,
        ?int $exitCode = null,
        string $output = '',
    ): array {
        return [
            'ok' => $ok,
            'supported' => $supported,
            'reason' => $reason,
            'message' => $message,
            'platform' => $platform,
            'wireguard_address' => $address,
            'command' => $command,
            'exit_code' => $exitCode,
            'output' => $output,
        ];
    }
}
