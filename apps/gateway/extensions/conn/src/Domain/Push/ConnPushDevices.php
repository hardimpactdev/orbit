<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Orbit\Extensions\Conn\Models\ConnAttentionEvent;
use Orbit\Extensions\Conn\Models\ConnPushDevice;

/** Lifecycle maintenance for push device registrations and attention event retention. */
final readonly class ConnPushDevices
{
    public function __construct(private NodeAccessAuthorizer $access) {}

    public function removeForNode(int $nodeId): int
    {
        return ConnPushDevice::query()->where('node_id', $nodeId)->toBase()->delete();
    }

    /** Removes the Node's push devices once it has lost its last access edge. */
    public function removeIfNoAccess(Node $node): int
    {
        if ($this->access->hasAnyAccess($node)) {
            return 0;
        }

        return $this->removeForNode($node->id);
    }

    /** Prunes attention events older than 7 days. */
    public function pruneEvents(?Carbon $now = null): int
    {
        $cutoff = ($now ?? Carbon::now())->copy()->subDays(ConnPush::EventRetentionDays);

        return ConnAttentionEvent::query()->where('created_at', '<', $cutoff)->toBase()->delete();
    }
}
