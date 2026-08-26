<?php

declare(strict_types=1);

namespace App\Services\Nodes\Roles\RoleBaselines;

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Services\Tools\ToolCatalog;
use Orbit\Core\Nodes\NodeTld;
use RuntimeException;

class AppDevelopmentRoleBaseline implements RoleBaseline
{
    use ManagesNodeToolBaseline;

    public function __construct(
        private readonly ?ToolCatalog $toolCatalog = null,
    ) {}

    public function converge(Node $node, NodeRoleAssignment $assignment): void
    {
        if (! NodeTld::isValid($node->tld)) {
            throw new RuntimeException('The app-dev role requires a valid node TLD.');
        }

        if (! is_string($node->wireguard_address) || trim($node->wireguard_address) === '') {
            throw new RuntimeException('The app-dev role requires a WireGuard address.');
        }

        $this->removeTools($node, ['php']);
        $this->convergeTool($node, 'docker', 'installed');
        $this->convergeTools($node, ['caddy']);
        $this->convergeTool($node, 'php-cli', 'installed');
        $this->convergeTool($node, 'composer', 'installed');
        $this->convergeTool($node, 'bun', 'installed');
        $this->convergeTool($node, 'git', 'installed');
        $this->convergeTool($node, 'gh', 'installed');
        $this->convergeTool($node, 'laravel-installer', 'installed');
    }

    public function remove(Node $node, NodeRoleAssignment $assignment, bool $purgeData): void
    {
        $this->removeTools($node, [
            'caddy',
            'docker',
            'php',
            'php-cli',
            'composer',
            'bun',
            'git',
            'gh',
            'laravel-installer',
        ]);
    }

    protected function toolCatalog(): ToolCatalog
    {
        return $this->toolCatalog ?? app(ToolCatalog::class);
    }
}
