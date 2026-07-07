<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeToolBaselineConfigRenderer;
use App\Services\Tools\ToolCatalog;

trait ManagesNodeToolBaseline
{
    /**
     * @param  list<string>  $tools
     */
    protected function convergeTools(Node $node, array $tools): void
    {
        foreach ($tools as $tool) {
            $this->convergeTool($node, $tool);
        }
    }

    /**
     * @param  array<string, mixed>|null  $config
     */
    protected function convergeTool(
        Node $node,
        string $tool,
        string $expectedState = 'installed',
        ?array $config = null,
    ): void {
        if (! $this->toolCatalog()->supports($tool)) {
            return;
        }

        if (! $this->toolCatalog()->supportsPlatform($tool, $node->platform)) {
            return;
        }

        NodeTool::query()->updateOrCreate(
            [
                'node_id' => $node->id,
                'name' => $tool,
            ],
            [
                'expected_state' => $expectedState,
                'expected_version' => null,
                'config' => $config ?? $this->defaultToolConfig($tool, $node),
            ],
        );
    }

    protected function convergeOrbitCaddy(Node $node): void
    {
        $this->convergeTool($node, 'caddy');
    }

    /**
     * @param  list<string>  $tools
     */
    protected function removeTools(Node $node, array $tools): void
    {
        $supportedTools = array_values(array_filter(
            $tools,
            fn (string $tool): bool => $this->toolCatalog()->supports($tool),
        ));

        if ($supportedTools === []) {
            return;
        }

        NodeTool::query()
            ->where('node_id', $node->id)
            ->whereIn('name', $supportedTools)
            ->delete();
    }

    abstract protected function toolCatalog(): ToolCatalog;

    /**
     * @return array<string, mixed>|null
     */
    private function defaultToolConfig(string $tool, Node $node): ?array
    {
        return app(NodeToolBaselineConfigRenderer::class)->render($tool, $node);
    }
}
