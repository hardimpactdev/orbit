<?php

declare(strict_types=1);

namespace App\Services\Doctor;

/**
 * Process-family restore codes owned by DoctorReportRunner applyProcessIssue.
 *
 * This is the support decision applyProcessIssue consumes — not a passive copy.
 */
final class DoctorProcessRestoreSupport
{
    /**
     * @return array<string, string> code => restore_action
     */
    public static function restoreSupport(): array
    {
        return DoctorRestoreActionId::map([
            'process.runtime_unit_extra',
            'process.runtime_unit_unrenderable',
            'process.runtime_unit_missing',
            'process.runtime_unit_mismatch',
            'process.runtime_unit_down',
            'process.restart_policy_mismatch',
            'process.runtime_environment_mismatch',
        ]);
    }

    public static function supports(string $code): bool
    {
        return array_key_exists($code, self::restoreSupport());
    }
}
