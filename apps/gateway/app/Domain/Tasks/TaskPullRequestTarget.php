<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\GitHub\GitHubPullRequest;
use App\Domain\GitHub\GitHubRepository;
use App\Models\Task;

/** The published pull request of an external group, as the Gateway read it at admission. */
final readonly class TaskPullRequestTarget
{
    /** @param list<array{name: string, conclusion: string|null, status: string|null, head: string|null, app: string|null}> $checks */
    public function __construct(public GitHubRepository $repository, public int $number, public GitHubPullRequest $pull, public array $checks = []) {}

    /** @return array{project_id:int,group_id:int,repository:string,pr_number:int} */
    public function intent(Task $group): array
    {
        return ['project_id' => $group->project_id, 'group_id' => $group->id,
            'repository' => $this->repository->owner.'/'.$this->repository->name, 'pr_number' => $this->number];
    }
}
