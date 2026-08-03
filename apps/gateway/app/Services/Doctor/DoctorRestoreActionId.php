<?php

declare(strict_types=1);

namespace App\Services\Doctor;

/**
 * Formats catalog restore_action ids from issue codes.
 *
 * Handlers own which codes they support; this only names the action id.
 */
final class DoctorRestoreActionId
{
    public static function for(string $code): string
    {
        return 'restore_'.str_replace('.', '_', $code);
    }

    /**
     * @param  list<string>  $codes
     * @return array<string, string> code => restore_action
     */
    public static function map(array $codes): array
    {
        $map = [];

        foreach ($codes as $code) {
            $map[$code] = self::for($code);
        }

        return $map;
    }
}
