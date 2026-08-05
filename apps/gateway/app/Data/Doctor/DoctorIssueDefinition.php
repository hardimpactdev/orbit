<?php

declare(strict_types=1);

namespace App\Data\Doctor;

use App\Enums\DoctorIssueDisposition;

final readonly class DoctorIssueDefinition
{
    public function __construct(
        public string $code,
        public string $family,
        public DoctorIssueDisposition $disposition,
        public ?string $restoreAction = null,
    ) {}
}
