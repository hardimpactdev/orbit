<?php

declare(strict_types=1);

namespace App\Services\Doctor;

use App\Data\Doctor\DoctorIssue;

/**
 * Stable identity of a restorable issue set for no-progress detection.
 */
final class DoctorRestoreIssueSignature
{
    /**
     * @param  list<DoctorIssue>  $issues
     */
    public static function fromIssues(array $issues): string
    {
        $parts = array_map(
            static function (DoctorIssue $issue): string {
                $detailToken = json_encode($issue->detail, JSON_THROW_ON_ERROR);

                return "{$issue->family}|{$issue->code}|{$issue->key}|{$issue->node}|{$detailToken}";
            },
            $issues,
        );
        sort($parts);

        return implode("\n", $parts);
    }
}
