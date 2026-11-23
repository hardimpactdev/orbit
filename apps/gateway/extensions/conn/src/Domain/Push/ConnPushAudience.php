<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\StoredInteger;
use App\Models\Node;
use App\Models\Operation;
use Orbit\Extensions\Conn\Domain\ConnAttachedThreads;
use Orbit\Extensions\Conn\Domain\ConnProfileSections;
use Orbit\Extensions\Conn\Domain\ConnServerAccess;
use Orbit\Extensions\Conn\Models\ConnAttentionEvent;
use Orbit\Extensions\Conn\Models\ConnEnvironment;
use Orbit\Extensions\Conn\Models\ConnProfile;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;
use Orbit\Extensions\Conn\Models\ConnPushDevice;
use Orbit\Extensions\Conn\Models\ConnReservation;

/**
 * Resolves the push audience for one attention event at send time: active Nodes that can reach the event's environment
 * through NodeAccessAuthorizer (as conn:thread:list), whose profile binding matches when the environment or thread is
 * associated with a profile, and whose device preferences allow the event.
 */
final readonly class ConnPushAudience
{
    public function __construct(private ConnServerAccess $access) {}

    /** @return list<ApnsMessage> */
    public function resolve(ConnAttentionEvent $event): array
    {
        $environment = $event->environment;
        $environment->loadMissing('node');

        $profileIds = $this->matchingProfileIds($environment, $event->thread_id);

        $query = ConnPushDevice::query()->with('node')->orderBy('id');
        if ($profileIds !== []) {
            $query->whereIn(
                'node_id',
                ConnProfileBinding::query()->whereIn('conn_profile_id', $profileIds)->select('node_id'),
            );
        }

        $collapseId = hash('sha256', $event->environment_id.':'.$event->thread_id);
        $messages = [];

        foreach ($query->get() as $device) {
            $node = $device->node;
            if (! $node instanceof Node || $node->status !== LifecycleStatus::Active) {
                continue;
            }
            if (! $this->access->allows($node, $environment)) {
                continue;
            }

            if ($event->type === 'attention') {
                if ($event->kind === null || ! $device->allowsKind($event->kind)) {
                    continue;
                }
                $pushType = 'alert';
                $priority = in_array($event->kind, ConnPush::HighPriorityKinds, true) ? 10 : 5;
                $threadTitle = $device->hidesTitles() ? null : $event->thread_title;
            } else {
                $pushType = 'background';
                $priority = 5;
                $threadTitle = null;
            }

            $messages[] = new ApnsMessage(
                deviceRowId: $device->id,
                deviceId: $device->device_id,
                apnsToken: $device->apns_token,
                apnsEnvironment: $device->apns_environment,
                bundleId: $device->bundle_id,
                eventId: $event->event_id,
                type: $event->type,
                kind: $event->kind,
                environmentId: $event->environment_id,
                threadId: $event->thread_id,
                threadTitle: $threadTitle,
                pushType: $pushType,
                priority: $priority,
                collapseId: $collapseId,
            );
        }

        return $messages;
    }

    /**
     * Profile IDs associated with the environment or thread: the server Node's profile binding, the container Instance's
     * reservation/creator profile, and any profile that attached this thread. Empty when no profile scopes the event.
     *
     * @return list<int>
     */
    private function matchingProfileIds(ConnEnvironment $environment, string $threadId): array
    {
        $ids = [];

        if ($environment->node_id !== null) {
            $nodeProfile = ConnProfileBinding::query()->where('node_id', $environment->node_id)->value('conn_profile_id');
            if ($nodeProfile !== null) {
                $ids[] = StoredInteger::from($nodeProfile);
            }
        }

        if ($environment->instance_id !== null) {
            foreach (ConnReservation::query()->where('instance_id', $environment->instance_id)->whereNotNull('conn_profile_id')->pluck('conn_profile_id') as $reservationProfile) {
                $ids[] = StoredInteger::from($reservationProfile);
            }

            $creator = Operation::query()
                ->where('kind', 'instance.create')
                ->where('target_type', 'instance')
                ->where('target_id', $environment->instance_id)
                ->oldest('id')
                ->value('triggered_by');
            if (is_string($creator) && preg_match('/\Anode:([1-9][0-9]*)\z/D', $creator, $match) === 1) {
                $creatorProfile = ConnProfileBinding::query()->where('node_id', (int) $match[1])->value('conn_profile_id');
                if ($creatorProfile !== null) {
                    $ids[] = StoredInteger::from($creatorProfile);
                }
            }
        }

        foreach (ConnProfile::query()->orderBy('id')->get(['id', 'settings']) as $profile) {
            $section = $profile->settings[ConnProfileSections::AttachedThreads] ?? null;
            foreach (ConnAttachedThreads::entries($section) as $entry) {
                if ($entry['environmentId'] === $environment->environment_id && $entry['threadId'] === $threadId) {
                    $ids[] = $profile->id;

                    break;
                }
            }
        }

        return array_values(array_unique($ids));
    }
}
