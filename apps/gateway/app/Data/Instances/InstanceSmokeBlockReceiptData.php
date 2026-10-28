<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class InstanceSmokeBlockReceiptData extends Data
{
    public function __construct(public string $requestKey, public bool $found, public ?InstanceSmokeBlockData $block) {}
}
