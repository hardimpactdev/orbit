<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\NodeTool;
use App\Services\Tools\ToolCatalog;
use RuntimeException;

class DatabaseRoleBaseline implements RoleBaseline
{
    public function __construct(
        private readonly ?ToolCatalog $toolCatalog = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if ($node->role === 'gateway') {
            throw new RuntimeException('The database role cannot be assigned to a gateway node.');
        }

        if (! str_starts_with((string) $node->platform, 'ubuntu')) {
            throw new RuntimeException('The database role requires an Ubuntu host.');
        }

        if (! $this->toolCatalog()->supports('docker')) {
            return;
        }

        NodeTool::query()->updateOrCreate(
            [
                'node_id' => $node->id,
                'name' => 'docker',
            ],
            [
                'expected_state' => 'running',
                'expected_version' => null,
                'config' => null,
            ],
        );
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        NodeTool::query()
            ->where('node_id', $node->id)
            ->where('name', 'docker')
            ->delete();
    }

    private function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }
}
