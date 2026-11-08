<?php

declare(strict_types=1);

namespace App\Domain\Clusters;

use App\Models\Node;
use Illuminate\Support\Facades\DB;

/** Records phase evidence; receipts describe a completed check, never current network health. */
final readonly class ClusterDnsEvidence
{
    public const string Key = 'cluster_dns_evidence';

    /** @param array<string,mixed> $receipt */
    public function record(int $nodeId, array $receipt): void
    {
        if (! is_array($receipt['effective'] ?? null) || ! is_numeric($receipt['checked_at'] ?? null)) {
            return;
        }
        DB::transaction(function () use ($nodeId, $receipt): void {
            $node = Node::query()->lockForUpdate()->find($nodeId);
            if ($node === null) {
                return;
            }
            $settings = $node->settings ?? [];
            $previous = $settings[self::Key] ?? null;
            if (is_array($previous) && is_numeric($previous['revision'] ?? null) && is_numeric($receipt['revision'] ?? null)
                && $previous['revision'] > $receipt['revision']) {
                return;
            }
            $previousTime = is_array($previous) ? ($previous['checked_at'] ?? null) : null;
            if (is_numeric($previousTime) && $previousTime > $receipt['checked_at']) {
                return;
            }
            $settings[self::Key] = array_intersect_key($receipt, array_flip(['revision', 'effective', 'digest', 'selected_at', 'checked_at']));
            $node->update(['settings' => $settings]);
        });
    }
}
