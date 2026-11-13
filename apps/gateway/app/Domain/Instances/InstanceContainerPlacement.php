<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Project;

/** Project selection is recorded once; every lifecycle flow uses the runtime's project. */
final readonly class InstanceContainerPlacement
{
    public const string NestedProject = 'orbit-instances-nested';

    /** @return list<string> */
    public static function projects(): array
    {
        return [InstanceHostIntent::Project, self::NestedProject];
    }

    public static function project(Project $project): string
    {
        return $project->nested_fleet ? self::NestedProject : InstanceHostIntent::Project;
    }

    public static function assertHost(Project $project, Node $node): void
    {
        if ($project->nested_fleet && ! app(NodeSettingsNormalizer::class)->fromStored($node->settings)?->nested_fleet) {
            throw new ResourceOperationException('instance.nested_fleet_host_unavailable', 'The Node has not enabled nested-fleet Instance hosting.', 409);
        }
    }

    public static function image(string $project): string
    {
        return $project === self::NestedProject ? 'orbit-instance-nested' : InstanceImage::StableAlias;
    }

    /** @return array<string, string> */
    public static function config(string $project): array
    {
        return $project === self::NestedProject ? [
            'security.nesting' => 'true',
            'security.syscalls.intercept.mknod' => 'true',
            'security.syscalls.intercept.setxattr' => 'true',
            'security.idmap.isolated' => 'true',
            'security.idmap.size' => (string) InstanceHostIntent::TaskPlaceIds,
            'limits.memory' => '12GiB',
        ] : [];
    }
}
