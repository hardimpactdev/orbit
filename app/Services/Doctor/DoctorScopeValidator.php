<?php

declare(strict_types=1);

namespace App\Services\Doctor;

final readonly class DoctorScopeValidator
{
    /**
     * @param  list<string>  $families
     */
    public function validate(array $families, DoctorReportRunner $runner): ?DoctorValidationFailure
    {
        foreach ($families as $family) {
            if (! in_array($family, $runner->supportedFamilies(), true)) {
                return new DoctorValidationFailure(
                    code: 'scope_not_found',
                    message: "Doctor family '{$family}' is not available yet.",
                    meta: ['family' => $family],
                );
            }
        }

        return null;
    }
}
