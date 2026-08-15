<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Workspaces\WorkspacePlacement;

final readonly class AppDevelopmentPackagesMount
{
    public const string Target = '/packages';

    public function __construct(
        private NodeHostPaths $hostPaths = new NodeHostPaths,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    public static function isSafeSource(string $source): bool
    {
        return self::userForSafeSource($source) !== null;
    }

    public static function userForSafeSource(string $source): ?string
    {
        return NodeHostPaths::userForPackagesDirectory($source);
    }

    /**
     * @return array{source: string, target: string, read_only: bool}|null
     */
    public function forApp(App $app, ?Instance $instance = null): ?array
    {
        $node = $this->placement->runtimeNode($app, $instance);

        if (! $node instanceof Node) {
            return null;
        }

        return $this->forNode($node);
    }

    /**
     * @return array{source: string, target: string, read_only: bool}|null
     */
    public function forNode(Node $node): ?array
    {
        $node->loadMissing('roleAssignments');

        if (! $node->hasActiveRole(NodeRoleName::AppDevelopment->value)) {
            return null;
        }

        $nodeUser = trim($node->user ?: 'orbit');

        if ($nodeUser === '') {
            return null;
        }

        return [
            'source' => $this->hostPaths->packagesDirectory($node),
            'target' => self::Target,
            'read_only' => false,
        ];
    }
}
