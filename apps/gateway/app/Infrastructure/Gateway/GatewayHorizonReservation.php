<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Events\JobReserved;
use Throwable;

/** Horizon metadata must not discard a job that Laravel has already reserved. */
final readonly class GatewayHorizonReservation
{
    public function __construct(private JobRepository $jobs) {}

    public function handle(JobReserved $event): void
    {
        try {
            $this->jobs->reserved($event->connectionName, $event->queue, $event->payload);
        } catch (Throwable $exception) {
            Log::error('Horizon could not record a job reservation.', [
                'job_uuid' => $event->payload->id(),
                'connection' => $event->connectionName,
                'queue' => $event->queue,
                'host' => gethostname(),
                'pid' => getmypid(),
                'attempts' => $event->payload['attempts'],
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);
        }
    }
}
