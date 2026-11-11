<?php

declare(strict_types=1);

namespace App\Data\Operations;

use Spatie\LaravelData\Data;

/** Native progress metadata only; no command payload or output. */
final class OperationStepData extends Data
{
    public function __construct(
        public string $name,
        public string $job,
        public string $status,
        public ?string $started_at,
        public ?string $finished_at,
    ) {}
}
