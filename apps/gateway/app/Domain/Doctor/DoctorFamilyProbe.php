<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Data\Doctor\DoctorFamilyReportData;
use App\Models\Node;

interface DoctorFamilyProbe
{
    public function family(): DoctorFamily;

    /** @return list<DoctorResources> */
    public function resources(Node $node): array;

    public function inspect(DoctorNodeContext $context): DoctorFamilyReportData;
}
