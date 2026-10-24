<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

final readonly class MetricsGeneratedFile
{
    public function __construct(
        public string $path,
        public ProtectedMetricsSecret $contents,
    ) {}
}
