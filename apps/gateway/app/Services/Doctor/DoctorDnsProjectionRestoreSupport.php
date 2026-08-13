<?php

declare(strict_types=1);

namespace App\Services\Doctor;

/**
 * DNS projection restore codes owned by DoctorDnsProjectionRestorer::apply.
 */
final class DoctorDnsProjectionRestoreSupport
{
    /**
     * @return array<string, string> code => restore_action
     */
    public static function restoreSupport(): array
    {
        return DoctorRestoreActionId::map([
            'node.dns_mapping_mismatch',
            'proxy.dns_mapping_mismatch',
        ]);
    }

    public static function supports(string $code): bool
    {
        return array_key_exists($code, self::restoreSupport());
    }
}
