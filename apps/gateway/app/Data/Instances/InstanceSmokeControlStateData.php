<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class InstanceSmokeControlStateData extends Data
{
    /**
     * @param  array<string, InstanceSmokeControlEntryData>  $receipts
     * @param  list<string>  $fingerprints
     * @param  list<array<string, mixed>>  $alerts
     */
    public function __construct(public ?InstanceSmokeHoldStateData $hold = null, public array $receipts = [],
        public array $fingerprints = [], public array $alerts = []) {}
}
