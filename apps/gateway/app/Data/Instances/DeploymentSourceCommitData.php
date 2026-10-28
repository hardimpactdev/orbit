<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DeploymentSourceCommitData extends Data
{
    /** @param list<string> $parents */
    public function __construct(public string $sha, public array $parents, public bool $descendsFromDeployed) {}
}
