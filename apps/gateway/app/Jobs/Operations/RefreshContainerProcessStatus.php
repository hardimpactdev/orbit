<?php

declare(strict_types=1);

namespace App\Jobs\Operations;

use App\Domain\Gateway\GatewayQueue;
use App\Infrastructure\Processes\ContainerProcessObservation;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Throwable;

/** Starts a container Process's status read off the request, so a list never waits for it. Repeated requests share one job. */
final class RefreshContainerProcessStatus implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public int $uniqueFor = 60;

    public function __construct(public int $processId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::Operations->value);
    }

    public static function enqueue(int $processId): bool
    {
        if (ContainerProcessObservation::pending($processId)) {
            return false;
        }
        $job = new self($processId);
        $lock = app(UniqueLock::class);
        if (! $lock->acquire($job)) {
            return false;
        }
        try {
            Bus::dispatch($job);
        } catch (Throwable $error) {
            $lock->release($job);

            throw $error;
        }

        return true;
    }

    public function uniqueId(): string
    {
        return 'container-process-status-'.$this->processId;
    }

    public function handle(ContainerProcessObservation $observations): void
    {
        $observations->admit($this->processId);
    }
}
