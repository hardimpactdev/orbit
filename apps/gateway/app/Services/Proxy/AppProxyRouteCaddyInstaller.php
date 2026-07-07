<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Gateway\CaddyGlobalConfig;
use RuntimeException;

final readonly class AppProxyRouteCaddyInstaller
{
    public function __construct(
        private CaddyGlobalConfig $caddyGlobalConfig,
        private RemoteCaddyConfig $caddyConfig,
    ) {}

    public function installRouteConfig(
        Node $node,
        string $domain,
        string $content,
        bool $backend = false,
    ): RemoteShellResult {
        $write = $this->caddyConfig->writeSite($node, $domain, $content, $backend);

        if (! $write->successful()) {
            return $write;
        }

        $caddyToolConfig = $this->caddyToolConfig($node);

        if ($caddyToolConfig !== null) {
            $update = $this->caddyConfig->applyContainer($node, $this->containerConfig($caddyToolConfig));

            if (! $update->successful()) {
                return $update;
            }
        }

        return $this->caddyConfig->reload($node);
    }

    public function ensureGlobalCaddyfile(Node $node): void
    {
        $contents = $this->caddyConfig->readGlobal($node);

        if ($contents === null) {
            throw new RuntimeException("Global Caddyfile could not be read on node '{$node->name}'.");
        }

        $updated = $this->caddyGlobalConfig->ensure($contents);

        if ($updated === $contents) {
            return;
        }

        $result = $this->caddyConfig->writeGlobal($node, $updated);

        if (! $result->successful()) {
            throw new RuntimeException("Global Caddyfile could not be written on node '{$node->name}'.");
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function caddyToolConfig(Node $node): ?array
    {
        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->first();

        if (! $tool instanceof NodeTool || ! is_array($tool->config)) {
            return null;
        }

        return $tool->config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function containerConfig(array $config): array
    {
        $container = is_array($config['container'] ?? null)
            ? $config['container']
            : $config;

        $normalized = [];

        foreach ($container as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }
}
