<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencySourceData extends Data
{
    public function __construct(
        public string $id,
        public string $path,
        public string $ecosystem,
        public string $package_manager,
        public DependencyAttemptData $last_attempt,
        public ?string $source_commit,
        public ?string $observed_at,
        public string $freshness,
    ) {}
}
