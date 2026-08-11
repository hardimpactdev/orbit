<?php

declare(strict_types=1);

namespace App\Data\Doctor;

final readonly class DoctorRestoreProbe
{
    /**
     * @param  array<string, mixed>  $report
     * @param  list<DoctorIssue>  $issues
     */
    public function __construct(
        public array $report,
        public array $issues,
    ) {}
}
