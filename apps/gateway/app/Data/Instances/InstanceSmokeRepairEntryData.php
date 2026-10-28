<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class InstanceSmokeRepairEntryData extends Data
{
    public function __construct(public int $callerNodeId, public InstanceSmokeRepairData $repair) {}
}
