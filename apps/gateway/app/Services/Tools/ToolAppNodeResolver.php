<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Exceptions\AppSelectionResolutionFailed;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Apps\AppSelectorResolver;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class ToolAppNodeResolver
{
    public function __construct(
        private AppSelectorResolver $selectors,
        private NodeRoleAssignments $nodeRoleAssignments,
        private WorkspacePlacement $placement,
    ) {}

    public function resolve(?string $instance): ?Node
    {
        if ($instance === null) {
            return null;
        }

        try {
            $selection = $this->selectors->requireInstance(
                $this->selectors->resolveRequired($instance),
            );
        } catch (AppSelectionResolutionFailed) {
            return null;
        }

        if (! $selection->instance instanceof Instance) {
            return null;
        }

        $node = $this->placement->nodeForInstance($selection->instance);

        if (! $node instanceof Node) {
            return null;
        }

        if (! $node->isActive() || ! $this->nodeRoleAssignments->nodeHasActiveAppHostRole($node)) {
            return null;
        }

        return $node;
    }
}
