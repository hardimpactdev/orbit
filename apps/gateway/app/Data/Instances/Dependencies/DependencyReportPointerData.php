<?php

declare(strict_types=1);

namespace App\Data\Instances\Dependencies;

use Spatie\LaravelData\Data;

final class DependencyReportPointerData extends Data
{
    public function __construct(
        public string $dataName,
        public int $version,
        public string $sourceCommit,
        public string $observedAt,
        public string $publicationId,
    ) {}
}
