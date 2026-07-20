<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Models\Node;

final readonly class ToolTargetSelectionResolver
{
    public function __construct(
        private ToolTargetNodeResolver $nodes,
    ) {}

    public function resolveTarget(
        string $tool,
        ?string $node,
        ?string $app,
    ): Node|ToolRegistryFailure {
        $validation = $this->validateFilters($node, $app, $tool);

        if ($validation instanceof ToolRegistryFailure) {
            return $validation;
        }

        $targetNode = $this->resolveFilter($node, $app, $tool);

        if ($targetNode instanceof Node) {
            return $targetNode;
        }

        return ToolRegistryFailure::validation(
            'target',
            '',
            'A node or instance target is required. Provide --node or --instance.',
        );
    }

    public function resolveFilter(?string $node, ?string $app, ?string $tool = null): ?Node
    {
        return $this->nodes->resolveFilter($node, $app, $tool);
    }

    public function resolveStored(?string $node, ?string $app): ?Node
    {
        return $this->nodes->resolveStored($node, $app);
    }

    public function validateFilters(
        ?string $node = null,
        ?string $app = null,
        ?string $tool = null,
    ): ?ToolRegistryFailure {
        $nodeFilter = null;

        if ($node !== null) {
            $nodeFilter = $this->nodes->resolveFilter($node, null, $tool);

            if (! $nodeFilter instanceof Node) {
                return ToolRegistryFailure::validation(
                    'node',
                    $node,
                    "Invalid value for --node: '{$node}'. Expected a visible tool node name.",
                );
            }
        }

        if ($app === null) {
            return null;
        }

        $appNode = $this->nodes->resolveFilter(null, $app);

        if (! $appNode instanceof Node) {
            return ToolRegistryFailure::validation(
                'instance',
                $app,
                "Invalid value for --instance: '{$app}'. Expected a visible project.instance selector, domain, or instance host.",
            );
        }

        if ($nodeFilter instanceof Node && $nodeFilter->id !== $appNode->id) {
            return ToolRegistryFailure::validation(
                'instance',
                $app,
                "Invalid value for --instance: '{$app}'. Instance is not owned by the selected node.",
                [
                    'node' => $nodeFilter->name,
                    'resolved_node' => $appNode->name,
                    'reason' => 'target_mismatch',
                ],
            );
        }

        return null;
    }
}
