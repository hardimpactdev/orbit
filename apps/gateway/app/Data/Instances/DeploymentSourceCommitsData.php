<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DeploymentSourceCommitsData extends Data
{
    /** @param list<DeploymentSourceCommitData> $commits */
    public function __construct(public string $head, public array $commits, public bool $reachedBase, public bool $truncated) {}
}
