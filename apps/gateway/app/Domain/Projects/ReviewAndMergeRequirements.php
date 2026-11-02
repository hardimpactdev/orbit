<?php

declare(strict_types=1);

namespace App\Domain\Projects;

/** ADR 0203: review and merge names the check that must pass and publishes through the GitHub App. */
final readonly class ReviewAndMergeRequirements
{
    /** @return array<string, string> field => message for each requirement the values miss */
    public static function missing(?string $mergeCheck, ?ProjectSourceAccess $sourceAccess): array
    {
        $missing = [];
        if ($mergeCheck === null || trim($mergeCheck) === '') {
            $missing['merge_check'] = 'Review and merge needs a merge check, such as "Required checks".';
        }
        if ($sourceAccess !== ProjectSourceAccess::GitHubApp) {
            $missing['review_and_merge'] = 'Review and merge needs source access through the GitHub App.';
        }

        return $missing;
    }
}
