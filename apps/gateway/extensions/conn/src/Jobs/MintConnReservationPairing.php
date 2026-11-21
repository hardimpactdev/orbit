<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Jobs;

use App\Domain\Gateway\GatewayQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Orbit\Extensions\Conn\Domain\ConnReservations;

/**
 * Mints a one-time pairing link for a ready reserved clone in the background, so the reservation's `ready` state never waits on it
 * (docs/reference/conn.md#reserve-a-clone).
 */
final class MintConnReservationPairing implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public bool $failOnTimeout = true;

    public function __construct(public string $reservationId, public int $environmentId)
    {
        $this->onConnection(GatewayQueue::Connection)->onQueue(GatewayQueue::OperationStarts->value);
    }

    public function handle(ConnReservations $reservations): void
    {
        $reservations->mintPairing($this->reservationId, $this->environmentId);
    }
}
