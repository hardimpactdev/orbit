<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class InstanceSmokeHoldStateData extends Data
{
    public function __construct(public string $key, public int $caller, public InstanceSmokeSelectionData $expected,
        public int $operatorVersion, public int $cursor, public bool $resume, public int $lastId, public int $smokeAfter) {}
}
