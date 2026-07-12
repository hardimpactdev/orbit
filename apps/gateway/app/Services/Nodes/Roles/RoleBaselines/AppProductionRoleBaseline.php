<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Enums\Nodes\NodeRoleName;
use App\Enums\Nodes\NodeRoleStatus;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Nodes\Roles\NodeRoleAssignments;
use App\Services\Tools\ToolCatalog;
use App\Services\Tools\ToolScriptDispatcher;
use RuntimeException;

class AppProductionRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly ?ToolCatalog $toolCatalog = null,
        private readonly ?NodeRoleAssignments $nodeRoleAssignments = null,
        private readonly ?ToolScriptDispatcher $toolScriptDispatcher = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($this->nodeRoleAssignments()->nodeIsGateway($node)) {
            throw new RuntimeException('The app-prod role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The app-prod role requires an Ubuntu host.');
        }

        if (! is_string($node->host) || trim($node->host) === '') {
            throw new RuntimeException('The app-prod role requires a reachable host record.');
        }

        $this->removeTools($node, ['php']);
        $this->removeLaravelInstaller($node);
        $this->convergeTools($node, ['caddy']);
        $this->convergeTool($node, 'php-cli', 'installed');
        $this->convergeTool($node, 'composer', 'installed');
        $this->convergeTool($node, 'git', 'installed');
        $this->convergeTool($node, 'gh', 'installed');
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        if (! $this->hasDevelopmentRole($node)) {
            $this->removeLaravelInstaller($node);
        }

        $this->removeTools($node, ['caddy', 'php', 'php-cli', 'composer', 'git', 'gh']);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }

    private function nodeRoleAssignments(): NodeRoleAssignments
    {
        return $this->nodeRoleAssignments ?? app(NodeRoleAssignments::class);
    }

    private function hasDevelopmentRole(Node $node): bool
    {
        return $node
            ->roleAssignments()
            ->where('role', NodeRoleName::AppDevelopment->value)
            ->whereIn('status', [NodeRoleStatus::Pending->value, NodeRoleStatus::Active->value])
            ->exists();
    }

    private function removeLaravelInstaller(Node $node): void
    {
        $tool = NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'laravel-installer')
            ->first();

        if (! $tool instanceof NodeTool) {
            return;
        }

        $config = is_array($tool->config) ? $tool->config : [];
        $script = $this->toolCatalog()->removeScript('laravel-installer', $config);

        if (! is_string($script) || $script === '') {
            throw new RuntimeException('Laravel Installer cleanup is unavailable for app-prod convergence.');
        }

        $result = $this->toolScriptDispatcher()->run(
            node: $node,
            tool: 'laravel-installer',
            action: 'remove',
            script: $script,
        );

        if (! $result->successful()) {
            throw new RuntimeException('Laravel Installer cleanup failed during app-prod convergence.');
        }

        $tool->delete();
    }

    private function toolScriptDispatcher(): ToolScriptDispatcher
    {
        return $this->toolScriptDispatcher ?? app(ToolScriptDispatcher::class);
    }
}
