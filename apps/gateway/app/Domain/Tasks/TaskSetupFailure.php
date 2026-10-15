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

        return preg_match('/(?:HTTP(?:\/[0-9.]+)?(?: error)?[ \t]+429\b|API rate limit exceeded|GitHub API limit[^\r\n]*is exhausted|secondary rate limit)/i', $check->output ?? '') === 1;
    }
}
