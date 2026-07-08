<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationRun;
use App\Models\OperationStreamSubscriberLease;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

final readonly class OperationStreamSubscriberLeases
{
    /**
     * @return array{subscriber: string, active_subscribers: int, expires_at: Carbon}
     */
    public function join(OperationRun $operationRun, string $channel, string $subscriber): array
    {
        $this->expire($operationRun, $channel);

        $expiresAt = Carbon::now()->addSeconds($this->ttlSeconds());

        OperationStreamSubscriberLease::query()->updateOrCreate(
            [
                'operation_run_id' => $operationRun->id,
                'channel' => $channel,
                'subscriber' => $subscriber,
            ],
            [
                'expires_at' => $expiresAt,
                'left_at' => null,
            ],
        );

        return [
            'subscriber' => $subscriber,
            'active_subscribers' => $this->activeCount($operationRun, $channel),
            'expires_at' => $expiresAt,
        ];
    }

    /**
     * @return array{subscriber: string, active_subscribers: int}
     */
    public function leave(OperationRun $operationRun, string $channel, string $subscriber): array
    {
        OperationStreamSubscriberLease::query()
            ->where('operation_run_id', $operationRun->id)
            ->where('channel', $channel)
            ->where('subscriber', $subscriber)
            ->whereNull('left_at')
            ->update(['left_at' => Carbon::now()]);

        $this->expire($operationRun, $channel);

        return [
            'subscriber' => $subscriber,
            'active_subscribers' => $this->activeCount($operationRun, $channel),
        ];
    }

    public function activeCount(OperationRun $operationRun, string $channel): int
    {
        $this->expire($operationRun, $channel);

        return OperationStreamSubscriberLease::query()
            ->where('operation_run_id', $operationRun->id)
            ->where('channel', $channel)
            ->whereNull('left_at')
            ->where('expires_at', '>', Carbon::now())
            ->count();
    }

    public function shouldStopTail(OperationRun $operationRun, string $channel): bool
    {
        return $this->activeCount($operationRun, $channel) === 0;
    }

    private function expire(OperationRun $operationRun, string $channel): void
    {
        OperationStreamSubscriberLease::query()
            ->where('operation_run_id', $operationRun->id)
            ->where('channel', $channel)
            ->whereNull('left_at')
            ->where('expires_at', '<=', Carbon::now())
            ->update(['left_at' => Carbon::now()]);
    }

    private function ttlSeconds(): int
    {
        $value = Config::get('orbit.operations.subscriber_lease_ttl_seconds', 60);

        return max(1, is_numeric($value) ? (int) $value : 60);
    }
}
