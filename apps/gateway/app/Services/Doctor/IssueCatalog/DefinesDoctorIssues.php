<?php

declare(strict_types=1);

namespace App\Services\Doctor\IssueCatalog;

use App\Data\Doctor\DoctorIssueDefinition;
use App\Enums\DoctorIssueDisposition;

trait DefinesDoctorIssues
{
    private static function genuine(string $code, string $family, string $restoreAction): DoctorIssueDefinition
    {
        return new DoctorIssueDefinition(
            code: $code,
            family: $family,
            disposition: DoctorIssueDisposition::GenuineDrift,
            restoreAction: $restoreAction,
        );
    }

    private static function blocked(string $code, string $family): DoctorIssueDefinition
    {
        return new DoctorIssueDefinition(
            code: $code,
            family: $family,
            disposition: DoctorIssueDisposition::BlockedInspection,
            restoreAction: null,
        );
    }

    private static function invalid(string $code, string $family): DoctorIssueDefinition
    {
        return new DoctorIssueDefinition(
            code: $code,
            family: $family,
            disposition: DoctorIssueDisposition::InvalidIntent,
            restoreAction: null,
        );
    }

    private static function incident(string $code, string $family): DoctorIssueDefinition
    {
        return new DoctorIssueDefinition(
            code: $code,
            family: $family,
            disposition: DoctorIssueDisposition::RuntimeIncident,
            restoreAction: null,
        );
    }
}
