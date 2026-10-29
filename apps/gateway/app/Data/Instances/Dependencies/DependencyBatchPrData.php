<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyBatchPrData extends Data
{
    public function __construct(
        public string $status,
        public ?string $checked_at,
        public ?string $last_success_at,
        public ?string $error_code,
        public ?DependencyPullRequestData $value,
    ) {}
}
