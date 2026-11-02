<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;

final readonly class ExporterSelector
{
    public function __construct(private RoleRegistry $roles = new RoleRegistry) {}

    /** @param list<RoleName> $roles */
    public function select(
        array $roles,
        bool $eligible,
        ?ExporterPreference $preference = null,
        bool $isMetricsNode = false,
    ): ExporterSelection {
        if (! $eligible) {
            return new ExporterSelection(false, ExporterSelectionReason::Ineligible);
        }

        if ($isMetricsNode) {
            return new ExporterSelection(true, ExporterSelectionReason::MetricsNode);
        }

        if ($preference === ExporterPreference::Enabled) {
            return new ExporterSelection(true, ExporterSelectionReason::ExplicitEnabled);
        }

        if ($preference === ExporterPreference::Disabled) {
            return new ExporterSelection(false, ExporterSelectionReason::ExplicitDisabled);
        }

        $hasActiveRole = array_any($roles, fn (RoleName $role): bool => $this->roles->definition($role)->metrics);

        return new ExporterSelection(
            $hasActiveRole,
            $hasActiveRole
                ? ExporterSelectionReason::RoleDefault
                : ExporterSelectionReason::RolelessDefaultExcluded,
        );
    }
}
