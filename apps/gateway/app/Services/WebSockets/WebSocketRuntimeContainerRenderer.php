<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Models\Node;
use App\Services\Runtime\OrbitContainerNames;
use InvalidArgumentException;
use RuntimeException;

class WebSocketRuntimeContainerRenderer
{
    public function __construct(
        private readonly OrbitContainerNames $names,
        private readonly WebSocketBackendName $backendName,
    ) {}

    public function render(
        Node $node,
        WebSocketRoleSettings $settings,
        string $sourcePath = WebSocketRuntimeContainer::SourceHostPath,
        string $image = 'dunglas/frankenphp:1-php8.5-bookworm',
    ): WebSocketRuntimeContainer {
        $backendName = $this->backendName->forNode($node);
        $wireGuardAddress = $this->wireGuardAddress($node);

        return new WebSocketRuntimeContainer(
            name: $this->containerName($node),
            image: $image,
            network: $this->names->network(),
            restartPolicy: 'unless-stopped',
            backendName: $backendName,
            redisNodeId: $settings->redisNodeId,
            workingDirectory: WebSocketRuntimeContainer::SourceTarget,
            command: $this->command($wireGuardAddress, $backendName),
            environment: $this->environment($wireGuardAddress),
            mounts: [
                [
                    'source' => $this->normalizeSourcePath($sourcePath),
                    'target' => WebSocketRuntimeContainer::SourceTarget,
                    'read_only' => false,
                ],
            ],
            networkAliases: [
                $this->containerName($node),
                $backendName,
            ],
        );
    }

    public function env(Node $node, WebSocketRoleSettings $settings): string
    {
        $container = $this->render($node, $settings);
        $lines = [];

        foreach ($container->environment() as $key => $value) {
            $lines[] = "{$key}={$value}";
        }

        return implode("\n", $lines)."\n";
    }

    public function containerName(Node $node): string
    {
        $name = trim($node->name);

        if ($name === '') {
            throw new InvalidArgumentException('The websocket runtime container requires a node name.');
        }

        return "orbit-websocket-{$name}";
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $wireGuardAddress): array
    {
        return [
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
            'BROADCAST_CONNECTION' => 'reverb',
            'ORBIT_WEBSOCKET_APPS_CONFIG' => WebSocketRuntimeSourceInstaller::AppsConfigPath,
            'REDIS_HOST' => 'redis.orbit',
            'REDIS_PORT' => '6379',
            'REVERB_HOST' => 'websocket.orbit',
            'REVERB_PORT' => '443',
            'REVERB_SCALING_ENABLED' => 'true',
            'REVERB_SCHEME' => 'https',
            'REVERB_SERVER_HOST' => $wireGuardAddress,
            'REVERB_SERVER_PORT' => '8080',
        ];
    }

    private function command(string $wireGuardAddress, string $backendName): string
    {
        return "php artisan reverb:start --host={$wireGuardAddress} --port=8080 --hostname={$backendName}";
    }

    private function wireGuardAddress(Node $node): string
    {
        $wireGuardAddress = trim((string) $node->wireguard_address);

        if ($wireGuardAddress === '') {
            throw new RuntimeException('The websocket role requires a WireGuard address before runtime config can be rendered.');
        }

        return $wireGuardAddress;
    }

    private function normalizeSourcePath(string $sourcePath): string
    {
        $sourcePath = trim($sourcePath);

        if ($sourcePath === '') {
            throw new InvalidArgumentException('The websocket runtime source path cannot be empty.');
        }

        if ($sourcePath === '/') {
            return $sourcePath;
        }

        return rtrim($sourcePath, '/');
    }
}
