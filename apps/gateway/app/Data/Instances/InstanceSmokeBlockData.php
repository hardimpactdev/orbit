<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class InstanceSmokeBlockData extends Data
{
    public function __construct(public string $requestKey, public int $instanceId, public InstanceSmokeFailureData $failure,
        public bool $blocked = true) {}
}
