<?php

declare(strict_types=1);

namespace App\Services\Php;

use App\Data\Php\PhpRuntimeImageInventory;
use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolsProbe;

final readonly class PhpRuntimeImageInventoryStore
{
    public function __construct(
        private PhpRuntimeImageInventoryMapper $mapper,
        private ToolCatalog $toolCatalog,
        private ToolsProbe $toolsProbe,
    ) {}

    public function stored(Node $node): PhpRuntimeImageInventory
    {
        $tool = $this->phpTool($node);

        if (! $tool instanceof NodeTool) {
            return new PhpRuntimeImageInventory(
                status: 'unavailable',
                error: 'The PHP image inventory tool is not registered on the node.',
            );
        }

        $config = is_array($tool->config) ? $tool->config : [];

        return $this->mapper->stored($config);
    }

    public function refresh(Node $node): PhpRuntimeImageInventory
    {
        $tool = $this->registeredPhpTool($node);

        if (! $tool instanceof NodeTool) {
            return new PhpRuntimeImageInventory(
                status: 'unavailable',
                error: 'The PHP image inventory tool is not registered on the node.',
            );
        }

        $snapshot = $this->toolsProbe->introspect($tool);
        $observed = $snapshot->get('php') ?? [];
        $config = is_array($tool->config) ? $tool->config : [];

        if (($observed['image_inventory_available'] ?? null) !== true) {
            $error = is_string($observed['image_inventory_error'] ?? null)
                ? trim($observed['image_inventory_error'])
                : 'The Docker-backed PHP image inventory probe failed.';
            $config['image_inventory_status'] = 'unavailable';
            $config['image_inventory_error'] = $error;
            $config['image_inventory_observed_at'] = now()->toIso8601String();
            $tool->forceFill(['config' => $config])->save();

            return $this->mapper->unavailable($config, $error);
        }

        $inventory = $this->mapper->confirmed($observed['images'] ?? null);
        $config['images'] = $inventory->images;
        $config['versions'] = $inventory->versions;
        $config['image_inventory_status'] = 'confirmed';
        $config['image_inventory_observed_at'] = now()->toIso8601String();
        unset($config['image_inventory_error']);
        $tool->forceFill(['config' => $config])->save();

        return $inventory;
    }

    private function phpTool(Node $node): ?NodeTool
    {
        return NodeTool::query()->where('node_id', $node->id)->where('name', 'php')->first();
    }

    private function registeredPhpTool(Node $node): ?NodeTool
    {
        $tool = $this->phpTool($node);

        if ($tool instanceof NodeTool || ! $this->toolCatalog->supportsNode('php', $node)) {
            return $tool;
        }

        return NodeTool::query()->firstOrCreate([
            'node_id' => $node->id,
            'name' => 'php',
        ], [
            'expected_state' => 'installed',
            'config' => [],
        ]);
    }
}
