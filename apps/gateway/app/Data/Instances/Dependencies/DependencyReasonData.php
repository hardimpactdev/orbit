<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyReasonData extends Data
{
    public function __construct(
        public string $code,
        public string $text,
    ) {}
}
