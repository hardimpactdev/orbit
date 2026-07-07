<?php

declare(strict_types=1);

namespace App\Services\Proxy;

use App\Models\Node;
use App\Models\NodeTool;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
final class CaddyContainerHostPathResolver
{
    public function resolve(Node $node, string $containerPath): string
    {
        $mounts = $this->managedCaddyMounts($node);

        if ($mounts === null) {
            return $containerPath;
        }

        $bestTarget = null;
        $bestSource = null;

        foreach ($mounts as $mount) {
            if (! is_array($mount)) {
                continue;
            }

            $source = $mount['source'] ?? null;
            $target = $mount['target'] ?? null;

            if (! is_string($source) || ! is_string($target) || $source === '' || $target === '') {
                continue;
            }

            if (! $this->pathIsWithinMount($containerPath, $target)) {
                continue;
            }

            if ($bestTarget === null || strlen($target) > strlen($bestTarget)) {
                $bestTarget = rtrim(string: $target, characters: '/');
                $bestSource = rtrim(string: $source, characters: '/');
            }
        }

        if ($bestTarget === null || $bestSource === null) {
            return $containerPath;
        }

        $suffix = substr($containerPath, strlen($bestTarget));

        return $bestSource.$suffix;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function managedCaddyMounts(Node $node): ?array
    {
        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'caddy')
            ->first();

        if (! $tool instanceof NodeTool) {
            return null;
        }

        $config = is_array($tool->config) ? $tool->config : [];
        $container = is_array($config['container'] ?? null) ? $config['container'] : [];
        $mounts = $container['mounts'] ?? null;

        return is_array($mounts) ? $mounts : null;
    }

    private function pathIsWithinMount(string $path, string $mountTarget): bool
    {
        $mountTarget = rtrim(string: $mountTarget, characters: '/');

        return $path === $mountTarget || str_starts_with($path, "{$mountTarget}/");
    }
}
