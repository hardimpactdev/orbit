<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\OperationEvent;
use App\Support\Streaming\ProgressEventStreamEmitter;
use Orbit\Core\Progress\ProgressEventType;

final readonly class OperationProgressEventEmitter
{
    public function emit(ProgressEventStreamEmitter $events, ?OperationEvent $event): void
    {
        if ($event === null) {
            $events->heartbeat();

            return;
        }

        $eventType = ProgressEventType::tryFrom($event->event_type);

        if ($eventType instanceof ProgressEventType) {
            $events->event($event->event_type, $event->payload, $event->sequence);

            return;
        }

        $events->event(
            ProgressEventType::Step->value,
            [
                ...$event->payload,
                'event' => $event->event_type,
            ],
            $event->sequence,
        );
    }
}
