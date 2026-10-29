<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyIndexedSourceData extends Data
{
    public function __construct(
        public string $id,
        public string $path,
        public string $ecosystem,
        public string $manager,
        public DependencyAttemptData $attempt,
        public ?DependencyReportPointerData $report,
    ) {}
}
