<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use Illuminate\Http\Client\PendingRequest;
use UnexpectedValueException;

/** Shared strict reads and date-only rewrites for nightly recipe PRs. */
final readonly class NightlyRecipeContents
{
    /** @phpstan-assert-if-true string $value */
    public function isSha(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[0-9a-f]{40}\z/', $value) === 1;
    }

    public function read(PendingRequest $http, string $ref): string
    {
        $encoded = $http->get('/contents/'.OpenNightlyRecipePullRequestAction::Path, ['ref' => $ref])->throw()->json('content');
        $content = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($content)) {
            throw new UnexpectedValueException('GitHub omitted the recipe contents.');
        }

        return $content;
    }

    public function replace(string $content, string $old, string $snapshot): string
    {
        $updated = preg_replace('/("snapshot"\s*:\s*")'.preg_quote($old, '/').'(")/', '${1}'.$snapshot.'${2}', $content, 1, $count);
        if (! is_string($updated) || $count !== 1) {
            throw new UnexpectedValueException('The recipe snapshot could not be replaced.');
        }

        return $updated;
    }
}
