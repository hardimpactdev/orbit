<?php

declare(strict_types=1);

namespace App\Data\Instances;

use Spatie\LaravelData\Data;

final class InstanceSmokeControlEntryData extends Data
{
    public function __construct(public int $caller, public string $fingerprint, public InstanceSmokeControlReceiptData $receipt) {}
}
