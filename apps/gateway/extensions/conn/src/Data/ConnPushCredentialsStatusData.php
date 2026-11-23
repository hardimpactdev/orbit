<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** Public status of stored APNs provider credentials. Never contains the private key. */
#[MapOutputName(SnakeCaseMapper::class)]
final class ConnPushCredentialsStatusData extends Data
{
    public function __construct(
        public bool $configured,
        public ?string $keyId,
        public ?string $teamId,
    ) {}
}
