<?php

declare(strict_types=1);

namespace App\Jobs\Observation;

use App\Domain\Gateway\GatewayQueue;
use App\Domain\Observation\ScheduledObservation;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Coalesces committed public metadata changes outside the shared terminal reader. */
final class PublishObservationContext implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $uniqueFor = 300;

    public function __construct(public ?string $channel = null, public bool $force = false, public bool $registry = false)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Problems->value);
    }

    public function uniqueId(): string
    {
        return ($this->channel ?? 'all').($this->force ? ':force' : '').($this->registry ? ':registry' : '');
    }

    public function handle(ScheduledObservation $observation): void
    {
        $observation->publish($this->channel, $this->force, $this->registry);
    }
}
