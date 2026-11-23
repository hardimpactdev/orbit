<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Node;
use Illuminate\Support\Carbon;
use Orbit\Extensions\Conn\Models\ConnAttentionEvent;
use Orbit\Extensions\Conn\Models\ConnPairing;
use Orbit\Extensions\Conn\Models\ConnPushDevice;
use Orbit\Extensions\Conn\Models\ConnPushSend;

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

    /**
     * Removes the Node's push devices only when no live pairing remains for the Node.
     * Routine held-session renewals revoke the previous held session while the new one is already live, so devices stay.
     */
    public function removeIfNoLivePairings(int $nodeId): int
    {
        $now = Carbon::now();
        $hasLive = ConnPairing::query()
            ->where('node_id', $nodeId)
            ->whereNull('revoked_at')
            ->whereNull('withdrawn_at')
            ->where(static fn ($query) => $query
                ->where('held', false)
                ->orWhereNull('session_expires_at')
                ->orWhere('session_expires_at', '>', $now))
            ->exists();

        if ($hasLive) {
            return 0;
        }

        return $this->removeForNode($nodeId);
    }

    /** Prunes attention events and send records older than 7 days. */
    public function pruneEvents(?Carbon $now = null): int
    {
        $cutoff = ($now ?? Carbon::now())->copy()->subDays(ConnPush::EventRetentionDays);

        ConnPushSend::query()->where('created_at', '<', $cutoff)->toBase()->delete();

        return ConnAttentionEvent::query()->where('created_at', '<', $cutoff)->toBase()->delete();
    }
}
