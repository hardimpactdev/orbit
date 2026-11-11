<?php

declare(strict_types=1);

namespace App\Data\Operations;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/** Native progress metadata only; no command payload or output. */
final class OperationStepData extends Data
{
    public function __construct(
        public string $name,
        public string $job,
        public string $status,
        public ?string $started_at,
        public ?string $finished_at,
        public int|null|Optional $exit_code = new Optional,
    ) {}
}
