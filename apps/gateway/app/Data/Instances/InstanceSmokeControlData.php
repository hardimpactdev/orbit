<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class InstanceSmokeControlData extends Data
{
    public function __construct(public string $action, public string $holdKey, public InstanceSmokeSelectionData $expected,
        public ?string $smokeRequestKey, public ?string $rollbackRequestKey) {}
}
