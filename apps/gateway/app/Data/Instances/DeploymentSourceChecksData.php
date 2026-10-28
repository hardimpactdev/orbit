<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class DeploymentSourceChecksData extends Data
{
    /** @param list<DeploymentSourceCheckData> $runs */
    public function __construct(public ?string $gate, public array $runs) {}
}
