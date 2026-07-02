<?php

declare(strict_types=1);

namespace App\Data\Apps;

use App\Enums\Apps\DependencyAuditStatus;

final readonly class DependencyAuditParsedResult
{
    /**
     * @param  array<string, int>  $severityCounts
     * @param  list<array<string, mixed>>  $advisorySummary
     */
    public function __construct(
        public DependencyAuditStatus $status,
        public int $dangerCount,
        public int $warningCount,
        public array $severityCounts,
        public array $advisorySummary,
    ) {}
}
