<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyIndexData extends Data
{
    /** @param list<DependencyIndexedSourceData> $sources */
    public function __construct(
        public int $projectId,
        public string $repository,
        public string $ref,
        public ?string $commit,
        public string $generatedAt,
        public DependencyAttemptData $attempt,
        public array $sources,
        public DependencyBatchPrData $pr,
    ) {}
}
