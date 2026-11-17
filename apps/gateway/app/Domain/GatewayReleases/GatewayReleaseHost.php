<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

use App\Domain\Gateway\GatewayServingHost;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\Process;

/** The Node that hosts the serving release and the two Node Processes carried into its container. */
final readonly class GatewayReleaseHost
{
    public const string Project = 'orbit-releases';

    public const array Processes = ['horizon', 'schedule-work'];

    public static function inventory(Node $node): ?GatewayReleaseInventory
    {
        $layout = GatewayReleaseLayout::fromConfig();
        if (! $layout->usesContainers()) {
            return null;
        }

        return self::owns($node) ? $layout->observe() : null;
    }

    public static function owns(Node $node): bool
    {
        $host = app(GatewayServingHost::class)->nodeId() ?? app(NodeAccessAuthorizer::class)->activeGatewayId();

        return $host === $node->id;
    }

    public static function retiredFpm(Node $node): bool
    {
        $inventory = self::inventory($node);

        return $inventory !== null && count($inventory->retained) === 2
            && ! $node->roles()->whereIn('role', [RoleName::AppDev, RoleName::AppProd])->exists();
    }

    /** @return array{project:string,name:string,release:string}|null */
    public static function target(Node $node): ?array
    {
        $inventory = self::inventory($node);
        if ($inventory === null || $inventory->current === null) {
            return null;
        }

        return ['project' => self::Project, 'name' => 'gateway-'.$inventory->current, 'release' => $inventory->commits[$inventory->current]];
    }

    public static function hasProcess(Process $process): bool
    {
        return $process->owner_type === Node::class && $process->runtime === ProcessRuntime::Systemd
            && in_array($process->name, self::Processes, true);
    }
}
