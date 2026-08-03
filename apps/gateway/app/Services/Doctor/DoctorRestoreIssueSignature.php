<?php

declare(strict_types=1);

namespace App\Services\Doctor;

/**
 * Stable identity of a restorable issue set for no-progress detection.
 */
final class DoctorRestoreIssueSignature
{
    /**
     * @param  list<array<string, mixed>>  $issues
     */
    public static function fromIssues(array $issues): string
    {
        $parts = array_map(
            static function (array $issue): string {
                $family = is_string($issue['family'] ?? null) ? $issue['family'] : '';
                $code = '';

                if (is_string($issue['code'] ?? null)) {
                    $code = $issue['code'];
                } elseif (is_string($issue['key'] ?? null)) {
                    $code = $issue['key'];
                }

                $key = is_string($issue['key'] ?? null) ? $issue['key'] : '';
                $node = is_string($issue['node'] ?? null) ? $issue['node'] : '';
                $detail = is_array($issue['detail'] ?? null) ? $issue['detail'] : [];
                $detailToken = json_encode($detail, JSON_THROW_ON_ERROR);

                return "{$family}|{$code}|{$key}|{$node}|{$detailToken}";
            },
            $issues,
        );
        sort($parts);

        return implode("\n", $parts);
    }
}
