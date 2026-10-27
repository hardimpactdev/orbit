<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class DeploymentSourceCommitsData extends Data
{
    /** @param list<DeploymentSourceCommitData> $commits */
    public function __construct(public string $head, public array $commits) {}
}
