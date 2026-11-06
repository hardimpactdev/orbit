<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

final class StartTaskPackageData extends Data
{
    public function __construct(public string $manager, public string $package) {}
}
