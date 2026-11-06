<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Data;

final class StartTaskPrepData extends Data
{
    public function __construct(public string $command, public ?string $brief = null, public ?string $reference = null) {}
}
