<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyResultsData extends Data
{
    /** @param list<DependencySourceData> $sources */
    public function __construct(
        public int $project_id,
        public ?int $instance_id,
        public string $source_ref,
        public ?string $instance_commit,
        public string $availability,
        public ?string $read_at,
        public ?string $generated_at,
        public ?string $source_commit,
        public ?DependencyAttemptData $last_attempt,
        public array $sources,
        public ?DependencyReportData $report,
        public DependencyBatchPrData $batch_pr,
    ) {}
}
