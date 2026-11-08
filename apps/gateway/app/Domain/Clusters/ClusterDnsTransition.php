<?php

declare(strict_types=1);

namespace App\Domain\Clusters;

use App\Models\Node;
use Illuminate\Support\Facades\DB;

/** Retained DNS selection intent: membership is not retired before the member confirms withdrawal. */
final readonly class ClusterDnsTransition
{
    public const string Key = 'cluster_dns_transition';

    public function begin(Node $node, int $clusterId, ?int $targetClusterId): void
    {
        DB::transaction(function () use ($node, $clusterId, $targetClusterId): void {
            $locked = Node::query()->lockForUpdate()->findOrFail($node->id);
            $settings = $locked->settings ?? [];
            $settings[self::Key] = ['cluster_id' => $clusterId, 'target_cluster_id' => $targetClusterId];
            $locked->update(['settings' => $settings]);
        });
    }

    public function complete(Node $node): void
    {
        DB::transaction(function () use ($node): void {
            $locked = Node::query()->lockForUpdate()->findOrFail($node->id);
            $settings = $locked->settings ?? [];
            unset($settings[self::Key]);
            $locked->update(['settings' => $settings]);
        });
    }
}
