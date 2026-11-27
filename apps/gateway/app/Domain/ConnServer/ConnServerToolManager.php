<?php

declare(strict_types=1);

namespace App\Domain\ConnServer;

use App\Domain\Tools\InstalledToolInventory;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Models\ConnServerNode;
use App\Models\Node;

final readonly class ConnServerToolManager implements ToolManager
{
    public const string Package = 'conn-server';

    public function name(): ToolManagerName
    {
        return ToolManagerName::ConnServer;
    }

    public function supportsNode(Node $node): bool
    {
        $platform = match ([$node->platform, $node->architecture]) {
            ['linux', 'x86_64'] => 'linux',
            ['macos', 'arm64'], ['macos', 'aarch64'] => 'mac',
            default => null,
        };

        return $platform !== null && ConnServerNode::query()->where('node_id', $node->id)->where('platform', $platform)->exists();
    }

    public function validatePackage(string $package): bool
    {
        return $package === self::Package;
    }

    public function installedInventory(Node $node): InstalledToolInventory
    {
        throw new ToolManagerException('installed-version', 'Only the protected host rollout can verify the Conn Server release.');
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return preg_match('/\A[0-9]+\.[0-9]+\.[0-9]+\z/D', $rawVersion) === 1 ? $rawVersion : null;
    }
}
