<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Data\Nodes\RoleSettings\WebSocketRoleSettings;
use App\Models\Node;
use App\Services\Nodes\NodeWireGuardServiceAddress;
use App\Services\Runtime\OrbitContainerNames;
use InvalidArgumentException;
use RuntimeException;

class WebSocketRuntimeContainerRenderer
{
    public const string RuntimeImage = 'orbit-reverb:current';

    private const string ServerBindHost = '0.0.0.0';

    public function __construct(
        private readonly OrbitContainerNames $names,
        private readonly WebSocketBackendName $backendName,
        private readonly WebSocketValkeyResolver $valkeyResolver,
        private readonly NodeWireGuardServiceAddress $serviceAddress,
    ) {}

    public function render(
        Node $node,
        WebSocketRoleSettings $settings,
        ?string $sourcePath = WebSocketRuntimeContainer::SourceHostPath,
        string $image = self::RuntimeImage,
        ?string $appKey = null,
    ): WebSocketRuntimeContainer {
        $backendName = $this->backendName->forNode($node);
        $valkeyAddress = $this->valkeyAddress($settings, $node);

        return new WebSocketRuntimeContainer(
            name: $this->containerName($node),
            image: $image,
            network: $this->names->network(),
            restartPolicy: 'unless-stopped',
            backendName: $backendName,
            valkeyNodeId: $settings->valkeyNodeId,
            workingDirectory: WebSocketRuntimeContainer::SourceTarget,
            command: $this->command($backendName),
            environment: $this->environment($backendName, $valkeyAddress, $appKey),
            mounts: $this->mounts($sourcePath),
            networkAliases: [
                $this->containerName($node),
            ],
            publishedPorts: [
                "{$backendName}:8080:8080",
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

        return OrbitContainerNames::forNodeScope($this->containerScopeForNode($node))->e2eScopedName(
            "orbit-websocket-{$name}",
        );
    }

    /**
     * @return array<string, string>
     */
    private function environment(string $backendName, string $valkeyAddress, ?string $appKey = null): array
    {
        $environment = [
            'APP_DEBUG' => 'false',
            'APP_ENV' => 'production',
            'BROADCAST_CONNECTION' => 'reverb',
            'CACHE_STORE' => 'array',
            'ORBIT_WEBSOCKET_APPS_CONFIG' => WebSocketRuntimeSourceInstaller::AppsConfigPath,
            'REDIS_HOST' => $valkeyAddress,
            'REDIS_PORT' => '6379',
            'REVERB_HOST' => 'websocket.orbit',
            'REVERB_PORT' => '443',
            'REVERB_SCALING_ENABLED' => 'true',
            'REVERB_SCHEME' => 'https',
            'REVERB_SERVER_HOST' => self::ServerBindHost,
            'REVERB_SERVER_PORT' => '8080',
            'REVERB_TLS_CERT' => "/etc/orbit/certs/{$backendName}.crt",
            'REVERB_TLS_KEY' => "/etc/orbit/certs/{$backendName}.key",
        ];

        if (is_string($appKey) && trim($appKey) !== '') {
            $environment['APP_KEY'] = trim($appKey);
        }

        return $environment;
    }

    private function command(string $backendName): string
    {
        return 'php artisan reverb:start --host='.self::ServerBindHost." --port=8080 --hostname={$backendName}";
    }

    /**
     * @return list<array{source: string, target: string, read_only: bool}>
     */
    private function mounts(?string $sourcePath): array
    {
        $mounts = [
            [
                'source' => '/etc/orbit',
                'target' => '/etc/orbit',
                'read_only' => true,
            ],
        ];

        if ($sourcePath !== null) {
            array_unshift($mounts, [
                'source' => $this->normalizeSourcePath($sourcePath),
                'target' => WebSocketRuntimeContainer::SourceTarget,
                'read_only' => false,
            ]);
        }

        return $mounts;
    }

    private function valkeyAddress(WebSocketRoleSettings $settings, Node $node): string
    {
        $valkeyNode = $this->valkeyResolver->usableValkeyNode($settings->valkeyNodeId);

        if (! $valkeyNode instanceof Node) {
            throw new RuntimeException(
                'The websocket role requires an active Valkey node before runtime config can be rendered.',
            );
        }

        return $this->serviceAddress->forServiceOn($valkeyNode, $node, 'valkey');
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

    private function containerScopeForNode(Node $node): string
    {
        $host = trim($node->host);

        if ($host !== '' && filter_var($host, FILTER_VALIDATE_IP) === false) {
            return $host;
        }

        return trim($node->name);
    }
}
