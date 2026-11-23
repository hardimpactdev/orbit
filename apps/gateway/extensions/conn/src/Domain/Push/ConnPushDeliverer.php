<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain\Push;

use Illuminate\Support\Carbon;
use Orbit\Extensions\Conn\Models\ConnAttentionEvent;
use Orbit\Extensions\Conn\Models\ConnPushDevice;
use Orbit\Extensions\Conn\Models\ConnPushSend;

/**
 * Delivers one Conn attention event to its resolved audience with stale-event dropping,
 * per-thread and per-device rate limits (with hourly summary alert), invalid-token cleanup,
 * and audit send records that never store tokens or titles.
 */
final readonly class ConnPushDeliverer
{
    public function __construct(
        private ConnPushAudience $audience,
        private ApnsClient $apns,
    ) {}

    public function deliver(ConnAttentionEvent $event): void
    {
        $now = Carbon::now();
        if ($event->occurred_at->lt($now->copy()->subSeconds(ConnPush::StaleEventMaxAgeSeconds))) {
            return;
        }

        foreach ($this->audience->resolve($event) as $message) {
            $toSend = $this->applyRateLimits($message, $now);
            if (! $toSend instanceof ApnsMessage) {
                continue;
            }

            $result = $this->apns->send($toSend);
            $deletedReason = null;

            if ($result->shouldDeleteDevice()) {
                ConnPushDevice::query()->whereKey($toSend->deviceRowId)->toBase()->delete();
                $deletedReason = $result->reason;
            }

            ConnPushSend::query()->create([
                'event_id' => $toSend->eventId,
                'conn_push_device_id' => $toSend->deviceRowId,
                'environment_id' => $toSend->environmentId,
                'thread_id' => $toSend->threadId,
                'apns_environment' => $toSend->apnsEnvironment,
                'push_type' => $toSend->pushType,
                'is_summary' => $toSend->isSummary,
                'apns_status' => $result->status,
                'apns_id' => $result->apnsId,
                'apns_reason' => $result->reason,
                'device_deleted_reason' => $deletedReason,
                'created_at' => $now,
            ]);
        }
    }

    private function applyRateLimits(ApnsMessage $message, Carbon $now): ?ApnsMessage
    {
        // Background pushes (attention_cleared) bypass alert rate limits so banners clear promptly.
        if ($message->pushType !== 'alert') {
            return $message;
        }

        $threadCutoff = $now->copy()->subSeconds(ConnPush::ThreadAlertCooldownSeconds);
        $recentlyAlertedThread = ConnPushSend::query()
            ->where('conn_push_device_id', $message->deviceRowId)
            ->where('environment_id', $message->environmentId)
            ->where('thread_id', $message->threadId)
            ->where('push_type', 'alert')
            ->where('is_summary', false)
            ->where('apns_status', 200)
            ->where('created_at', '>', $threadCutoff)
            ->exists();

        if ($recentlyAlertedThread) {
            return null;
        }

        $hourCutoff = $now->copy()->subHour();
        $hourlyCount = ConnPushSend::query()
            ->where('conn_push_device_id', $message->deviceRowId)
            ->where('push_type', 'alert')
            ->where('is_summary', false)
            ->where('apns_status', 200)
            ->where('created_at', '>', $hourCutoff)
            ->count();

        if ($hourlyCount < ConnPush::MaxAlertsPerDevicePerHour) {
            return $message;
        }

        $summarySent = ConnPushSend::query()
            ->where('conn_push_device_id', $message->deviceRowId)
            ->where('push_type', 'alert')
            ->where('is_summary', true)
            ->where('apns_status', 200)
            ->where('created_at', '>', $hourCutoff)
            ->exists();

        if ($summarySent) {
            return null;
        }

        return $message->asSummary();
    }
}
