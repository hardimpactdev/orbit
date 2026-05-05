<?php

declare(strict_types=1);

namespace App\Services\Nodes;

use App\Models\Node;

final readonly class NodeAgentIdeDefaults
{
    /**
     * @var list<string>
     */
    public const array SUPPORTED_ADAPTERS = ['opencode', 'polyscope'];

    /**
     * @return array{
     *     name: string,
     *     agent_ide: array{adapter: string|null, source: string},
     *     action: string
     * }
     */
    public function set(Node $node, string $adapter): array
    {
        $normalizedAdapter = $adapter === 'none' ? null : $adapter;
        $currentAdapter = $this->currentAdapter($node);
        $action = $currentAdapter === $normalizedAdapter ? 'converged' : 'set';

        if ($action === 'set') {
            $node->agent_ide_config = $normalizedAdapter === null
                ? null
                : ['adapter' => $normalizedAdapter];
            $node->save();
        }

        return [
            'name' => $node->name,
            'agent_ide' => self::payloadFor($node),
            'action' => $action,
        ];
    }

    public function isSupported(string $adapter): bool
    {
        return $adapter === 'none' || in_array($adapter, self::SUPPORTED_ADAPTERS, true);
    }

    /**
     * @return array{adapter: string|null, source: string}
     */
    public static function payloadFor(Node $node): array
    {
        $adapter = self::adapterFromConfig($node->agent_ide_config);

        if ($adapter === null) {
            return [
                'adapter' => null,
                'source' => 'default',
            ];
        }

        return [
            'adapter' => $adapter,
            'source' => 'node',
        ];
    }

    private function currentAdapter(Node $node): ?string
    {
        return self::adapterFromConfig($node->agent_ide_config);
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    private static function adapterFromConfig(?array $config): ?string
    {
        $adapter = $config['adapter'] ?? null;

        return is_string($adapter) && $adapter !== '' ? $adapter : null;
    }
}
