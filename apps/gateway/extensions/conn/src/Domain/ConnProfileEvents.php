<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Broadcasting\NamedBroadcast;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\StoredInteger;
use App\Models\Node;
use App\Models\NodeAccess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;

/**
 * Tells the Nodes bound to a profile its committed document version over Reverb
 * ([Profile events](/reference/conn#profile-events)). Each binding has a random key that names its private channel. The
 * key is the binding's generation: every bind, edge removal, serving Node removal and observed lapse replaces it, and it
 * ends 15 minutes after it was issued. A write captures the keys of its audience under the profile lock and, after the
 * outermost commit, sends only to a key that is still current.
 */
final readonly class ConnProfileEvents
{
    public const string Event = 'conn.profile.updated';

    public const string ChannelPrefix = 'private-conn-profile.';

    public const int LifetimeMinutes = 15;

    /** `GET /me` and `PUT /me/profile` renew a key older than this. */
    public const int RenewalMinutes = 5;

    public function __construct(
        private RecordEventBroadcaster $broadcaster,
        private NodeAccessAuthorizer $access,
        private ExtensionStore $extensions,
    ) {}

    /** The binding's channel, or null without a key. */
    public static function channel(?ConnProfileBinding $binding): ?string
    {
        return $binding?->event_key === null ? null : self::ChannelPrefix.$binding->event_key;
    }

    /** Whether the binding holds a key that has not ended. */
    public static function current(ConnProfileBinding $binding): bool
    {
        return $binding->event_key !== null && $binding->event_key_issued_at !== null
            && $binding->event_key_issued_at->gt(Carbon::now()->subMinutes(self::LifetimeMinutes));
    }

    /** Gives the binding a new key. Keys are random, so two replacements in the same second give two keys. */
    public function replace(ConnProfileBinding $binding): void
    {
        $binding->forceFill(['event_key' => bin2hex(random_bytes(16)), 'event_key_issued_at' => Carbon::now()])->save();
    }

    /** Replaces the key of the Node's binding, if it has one. */
    public function replaceForNode(int $nodeId): void
    {
        DB::transaction(function () use ($nodeId): void {
            $binding = ConnProfileBinding::query()->where('node_id', $nodeId)->lockForUpdate()->first();

            if ($binding !== null) {
                $this->replace($binding);
            }
        });
    }

    /** Replaces the key of every Node with an edge to the serving Node, before its removal cascades the edges away. */
    public function replaceForConsumersOf(Node $serving): void
    {
        $consumers = NodeAccess::query()->where('serving_node_id', $serving->id)->pluck('consumer_node_id');

        foreach ($consumers as $consumer) {
            $this->replaceForNode(StoredInteger::from($consumer));
        }
    }

    /** For `GET /me`: issues a new key for a key older than 5 minutes or ended, and returns the binding. */
    public function renew(Node $node): ?ConnProfileBinding
    {
        return DB::transaction(function () use ($node): ?ConnProfileBinding {
            $binding = ConnProfileBinding::query()->where('node_id', $node->id)->lockForUpdate()->first();

            if ($binding !== null && ($binding->event_key_issued_at === null || $binding->event_key_issued_at->lte(Carbon::now()->subMinutes(self::RenewalMinutes)))) {
                $this->replace($binding);
            }

            return $binding;
        });
    }

    /**
     * Captures the audience of a write to the profile and sends the version once the outermost transaction commits. Call it
     * inside the write's transaction, under the profile lock. A rolled-back write sends nothing.
     */
    public function publish(int $profileId, int $version): void
    {
        $audience = [];

        foreach (ConnProfileBinding::query()->where('conn_profile_id', $profileId)->whereNotNull('event_key')->get() as $binding) {
            $audience[] = ['id' => $binding->id, 'key' => (string) $binding->event_key];
        }

        if ($audience === []) {
            return;
        }

        $this->broadcaster->afterCommit(function () use ($audience, $profileId, $version): void {
            if (! $this->extensions->enabled('conn')) {
                return;
            }

            foreach ($audience as $captured) {
                if ($this->stillCurrent($captured['id'], $captured['key'], $profileId)) {
                    $this->broadcaster->deliver(
                        new NamedBroadcast('conn-profile.'.$captured['key'], self::Event, ['profile_id' => $profileId, 'version' => $version]),
                        ['type' => self::Event, 'id' => $profileId],
                    );
                }
            }
        });
    }

    /**
     * The final check before a send: the binding still names the profile, holds the captured key and the key has not
     * ended, and its Node is active and passes the collection rule. A Node that fails the last two loses its key.
     */
    private function stillCurrent(int $bindingId, string $key, int $profileId): bool
    {
        $binding = ConnProfileBinding::query()->with('node')->find($bindingId);

        if ($binding === null || $binding->conn_profile_id !== $profileId || $binding->event_key !== $key || ! self::current($binding)) {
            return false;
        }

        $node = $binding->node;

        if ($node->status !== LifecycleStatus::Active || ! $this->access->hasAnyAccess($node)) {
            DB::transaction(function () use ($bindingId, $key): void {
                $locked = ConnProfileBinding::query()->lockForUpdate()->find($bindingId);

                if ($locked !== null && $locked->event_key === $key) {
                    $this->replace($locked);
                }
            });

            return false;
        }

        return true;
    }
}
