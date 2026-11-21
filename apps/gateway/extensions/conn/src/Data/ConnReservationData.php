<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Orbit\Extensions\Conn\Domain\ConnReservations;
use Orbit\Extensions\Conn\Models\ConnReservation;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** A reserved clone and, once ready and minted, its one-time pairing link (docs/reference/conn.md#reserve-a-clone). */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnReservationData extends Data
{
    public function __construct(
        public string $id,
        public int $projectId,
        public ?int $instanceId,
        public string $state,
        public string $expiresAt,
        public ?int $createOperationId,
        public ?string $environmentId,
        public ?int $finalizeOperationId,
        public ?int $destroyOperationId,
        public ?string $errorCode,
        public ?IssuedConnPairingData $pairing = null,
    ) {}

    public static function fromModel(ConnReservation $reservation, ?IssuedConnPairingData $pairing = null): self
    {
        return ConnReservations::snapshot($reservation, $pairing);
    }
}
