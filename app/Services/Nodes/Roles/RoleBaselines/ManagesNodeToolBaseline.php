<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeTool;
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

    protected function convergeTool(Node $node, string $tool, string $expectedState = 'running'): void
    {
        if (! $this->toolCatalog()->supports($tool)) {
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
                'config' => null,
            ],
        );
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
}
