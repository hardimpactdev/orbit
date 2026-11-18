<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Delivers a recorded executor wake notice without retaining the activation request worker. */
final class BroadcastOperationNotice implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    /** @param array<string, mixed> $notice */
    public function __construct(public RecordEventType $type, public int $operationId, public array $notice, public string $channel)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::OperationsPrompt->value);
    }

    public function handle(RecordEventBroadcaster $broadcaster): void
    {
        RecordEventBroadcaster::immediately(fn () => $broadcaster->broadcast($this->type, $this->operationId, $this->notice, $this->channel));
    }
}
