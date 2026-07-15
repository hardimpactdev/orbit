<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Models\Node;

final readonly class DoctorScopeValidator
{
    /**
     * @param  list<string>  $families
     */
    public function validate(
        array $families,
        DoctorReportRunner $runner,
        ?Node $target = null,
    ): ?DoctorValidationFailure {
        foreach ($families as $family) {
            if (! in_array($family, $runner->supportedFamilies(), true)) {
                return new DoctorValidationFailure(
                    code: 'scope_not_found',
                    message: "Doctor family '{$family}' is not available yet.",
                    meta: ['family' => $family],
                );
            }
        }

        if ($target instanceof Node) {
            $allowed = $runner->categoriesForNode($target);

            foreach ($families as $family) {
                if (! in_array($family, $allowed, true)) {
                    return new DoctorValidationFailure(
                        code: 'family_not_in_node_scope',
                        message: "Doctor family '{$family}' is not available for node '{$target->name}'.",
                        meta: [
                            'family' => $family,
                            'target_node' => $target->name,
                            'allowed_families' => $allowed,
                        ],
                    );
                }
            }
        }

        return null;
    }
}
