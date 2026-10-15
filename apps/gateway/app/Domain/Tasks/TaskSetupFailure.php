<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\TaskCheck;

final readonly class TaskSetupFailure
{
    public static function rateLimited(TaskCheck $check): bool
    {
        if ($check->status !== TaskCheckStatus::Failed || $check->failed_step === null
            || in_array($check->failed_step, ['check_error', 'invalid_deliverable'], true)) {
            return false;
        }

        $output = $check->output ?? '';
        $github = '(?:https?://)?(?:api\.|codeload\.)?github\.com';
        $status = '(?:HTTP(?:/[0-9.]+)?\s+|HTTP\s+error\s+|error:\s*)';
        $limit = '(?:secondary rate limit|rate limit exceeded)';

        return preg_match('~'.$github.'[^\r\n]*(?:\R[^\r\n]*){0,2}?'.$status.'429\b~i', $output) === 1
            || preg_match('~'.$status.'429\b[^\r\n]*'.$github.'~i', $output) === 1
            || preg_match('~(?:API rate limit exceeded|GitHub API limit[^\r\n]*is exhausted|'.$limit.'[\s\S]{0,512}?'.$github.'|'.$github.'[\s\S]{0,512}?'.$limit.')~i', $output) === 1;
    }
}
