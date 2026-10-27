<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class DeploymentSourceHeadData extends Data
{
    public function __construct(public string $head) {}
}
