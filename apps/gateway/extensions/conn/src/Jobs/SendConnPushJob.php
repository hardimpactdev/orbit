<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Jobs;

use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Orbit\Extensions\Conn\Domain\Push\ApnsClient;
use Orbit\Extensions\Conn\Domain\Push\ConnPush;
use Orbit\Extensions\Conn\Domain\Push\ConnPushAudience;
use Orbit\Extensions\Conn\Models\ConnAttentionEvent;

/**
 * Resolves the audience for a stored Conn attention event at send time and dispatches pushes through ApnsClient.
 */
final class SendConnPushJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public int $attentionEventId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::OperationStarts->value);
    }

    public function handle(ConnPushAudience $audience, ApnsClient $apns): void
    {
        if (! ConnPush::enabled()) {
            return;
        }

        $event = ConnAttentionEvent::query()->with('environment.node')->find($this->attentionEventId);
        if (! $event instanceof ConnAttentionEvent) {
            return;
        }

        foreach ($audience->resolve($event) as $message) {
            $apns->send($message);
        }
    }
}
