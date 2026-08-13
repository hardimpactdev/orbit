<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;

/** @mago-expect lint:cyclomatic-complexity */
final class DoctorIssueResolutionId
{
    /**
     * @param  array<string, mixed>  $action
     */
    public static function forAction(array $action): ?string
    {
        $family = is_string($action['family'] ?? null) ? $action['family'] : null;
        $key = is_string($action['key'] ?? null) ? $action['key'] : null;

        if ($family === null || $key === null) {
            return null;
        }

        $code = is_string($action['code'] ?? null) ? $action['code'] : $key;

        return "{$family}:{$key}:{$code}";
    }

    public static function forIssue(DoctorIssue $issue): string
    {
        return "{$issue->family}:{$issue->key}:{$issue->code}";
    }

    /**
     * @param  array<string, mixed>  $action
     */
    public static function databaseTargetForAction(array $action): ?string
    {
        if (
            ($action['family'] ?? null) !== 'database_connection'
            || ! in_array(
                $action['status'] ?? null,
                ['completed', 'created', 'updated'],
                strict: true,
            )
        ) {
            return null;
        }

        $detail = is_array($action['details'] ?? null) ? $action['details'] : [];

        return self::databaseTarget($detail);
    }

    public static function databaseTargetForIssue(DoctorIssue $issue): ?string
    {
        if ($issue->family !== 'database_connection') {
            return null;
        }

        return self::databaseTarget($issue->detail);
    }

    /**
     * @param  array<array-key, mixed>  $detail
     */
    private static function databaseTarget(array $detail): string
    {
        return implode(':', [
            (string) ($detail['target_type'] ?? ''),
            (string) ($detail['target_id'] ?? ''),
            (string) ($detail['env_prefix'] ?? ''),
        ]);
    }
}
