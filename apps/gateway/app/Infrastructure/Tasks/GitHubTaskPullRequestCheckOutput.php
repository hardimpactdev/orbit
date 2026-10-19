<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Tasks\TaskCheckOutputBuilder;
use App\Domain\Tasks\TaskPullRequestCheck;
use App\Domain\Tasks\TaskPullRequestCheckOutput;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;
use App\Models\Task;

final readonly class GitHubTaskPullRequestCheckOutput implements TaskPullRequestCheckOutput
{
    public function __construct(private GitHubActionsReader $actions, private TaskCheckOutputBuilder $outputBuilder) {}

    public function read(Task $group, TaskPullRequestCheck $check): string
    {
        if ($check->appSlug === 'github-actions') {
            $repository = GitHubRepository::fromOrigin((string) $group->project->repository_url);
            if ($repository === null || $check->id === null || $check->id < 1) {
                return 'Orbit could not read the log.';
            }
            try {
                $output = $this->outputBuilder->buildStream($this->actions->jobLog($repository, $check->id));
            } catch (GitHubActionsUnavailable) {
                return 'Orbit could not read the log.';
            }
        } else {
            $output = $this->outputBuilder->build($this->outputBuilder->tail(implode("\n\n", array_filter([$check->outputTitle, $check->outputSummary], static fn (?string $text): bool => $text !== null))));
        }

        if ($output === '') {
            return 'Orbit could not read the log.';
        }

        return "This is the CI evidence (a redacted excerpt of the failed check output):\n\n".$output;
    }
}
