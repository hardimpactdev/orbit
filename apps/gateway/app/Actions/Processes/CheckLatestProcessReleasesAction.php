<?php

declare(strict_types=1);

namespace App\Actions\Processes;

use App\Domain\Processes\Artifacts\GithubReleases;
use App\Models\ProcessArtifact;
use App\Support\ValidatedData;

/**
 * The daily release check (docs/reference/processes-and-schedules.md#upgrade-a-process): one read of the latest
 * non-prerelease release for each distinct artifact repository, stored on each of its artifacts. A failed read keeps the
 * last answer and is no finding.
 */
final readonly class CheckLatestProcessReleasesAction
{
    public function __construct(private GithubReleases $releases) {}

    /** @return array{checked: int, failed: int} */
    public function execute(): array
    {
        $checked = 0;
        $failed = 0;
        $repositories = ProcessArtifact::query()->where('source', ProcessArtifact::SourceGithubRelease)->distinct()->orderBy('repository')->pluck('repository')->map(ValidatedData::string(...));
        foreach ($repositories as $repository) {
            $checked++;
            $latest = $this->releases->latest($repository);
            if ($latest === null) {
                $failed++;

                continue;
            }
            ProcessArtifact::query()->where('source', ProcessArtifact::SourceGithubRelease)->where('repository', $repository)
                ->update(['latest_version' => $latest, 'latest_checked_at' => now()]);
        }

        return ['checked' => $checked, 'failed' => $failed];
    }
}
