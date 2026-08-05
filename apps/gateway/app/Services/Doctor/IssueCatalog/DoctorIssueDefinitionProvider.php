<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;

interface DoctorIssueDefinitionProvider
{
    /**
     * @return list<DoctorIssueDefinition>
     */
    public function definitions(): array;
}
