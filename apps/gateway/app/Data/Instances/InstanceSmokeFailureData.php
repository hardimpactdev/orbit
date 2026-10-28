<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class InstanceSmokeFailureData extends Data
{
    public function __construct(public int $deploymentId, public string $commit, public string $release, public string $smokeRequestKey) {}

    public function selection(): InstanceSmokeSelectionData
    {
        return new InstanceSmokeSelectionData($this->deploymentId, $this->commit, $this->release);
    }
}
