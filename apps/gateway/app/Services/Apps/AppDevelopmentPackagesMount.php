<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Nodes\NodeRoleName;
use App\Models\App;
use App\Models\Node;
use App\Services\Nodes\NodeHostPaths;

final readonly class AppDevelopmentPackagesMount
{
    public const string Target = '/packages';

    public function __construct(
        private NodeHostPaths $hostPaths = new NodeHostPaths,
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
    public function forApp(App $app): ?array
    {
        $app->loadMissing('node.roleAssignments');

        if (! $app->node instanceof Node) {
            return null;
        }

        if (! $app->node->hasActiveRole(NodeRoleName::AppDevelopment->value)) {
            return null;
        }

        $nodeUser = trim($app->node->user ?: 'orbit');

        if ($nodeUser === '') {
            return null;
        }

        return [
            'source' => $this->hostPaths->packagesDirectory($app->node),
            'target' => self::Target,
            'read_only' => false,
        ];
    }
}
