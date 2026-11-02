<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubApiException;
use App\Domain\Projects\ProjectGitHubSource;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Domain\Tasks\TaskStartCommit;
use App\Models\Project;

/** Pins the start commit through the Gateway GitHub App, with the same first-parent walk and bound as deploy source reads. */
final readonly class GitHubTaskStartCommit implements TaskStartCommit
{
    public function __construct(private ProjectGitHubSource $source, private RemoteBranchHeads $heads) {}

    public function resolve(Project $project): string
    {
        $branch = (string) $project->default_branch;
        $check = $project->mergeCheckName();
        if ($check === null) {
            try {
                return $this->heads->head($project, $branch);
            } catch (ResourceOperationException $exception) {
                throw self::unavailable($branch, $exception);
            }
        }
        try {
            $access = $this->source->access($project)
                ?? throw new ResourceOperationException('tasks.source_unreadable', "The Project must read its repository through the GitHub App to check [{$check}].", 409);
            $green = $this->source->newestGreen($access, $branch, $check);
        } catch (GitHubApiException $exception) {
            report($exception);

            throw self::unavailable($branch, $exception);
        }

        return $green ?? throw new ResourceOperationException('tasks.no_green_commit',
            'None of the newest '.ProjectGitHubSource::CandidateLimit." commits of [{$branch}] passed [{$check}].", 409);
    }

    private static function unavailable(string $branch, \Throwable $previous): ResourceOperationException
    {
        return new ResourceOperationException('tasks.source_unavailable', "The Gateway could not read the start commit on [{$branch}].", 502, $previous);
    }
}
