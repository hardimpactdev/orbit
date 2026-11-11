<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\GitHub\GitHubApi;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\RepositoryReadContext;
use App\Domain\Instances\IntendedInstanceBranch;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\SourceControl\ReadDeadline;
use App\Infrastructure\GitHub\HttpGitHubBranchPullRequests;
use App\Infrastructure\SourceControl\GitRemoteBranchHeads;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Orbit\Extensions\Conn\Data\ConnBranchInstanceData;
use Orbit\Extensions\Conn\Data\ConnBranchPullRequestData;
use Orbit\Extensions\Conn\Data\ConnProjectBranchData;
use Throwable;

/** Remote branch choices for Conn; authorization belongs to the request. */
final readonly class ConnProjectBranches
{
    public const int Limit = 100;

    public function __construct(
        private GitRemoteBranchHeads $branches,
        private GitHubApi $github,
        private HttpGitHubBranchPullRequests $pulls,
        private NodeAccessAuthorizer $access,
    ) {}

    /** @return array{data: list<ConnProjectBranchData>, pull_requests_unavailable: bool} */
    public function read(Node $caller, Project $project, string $query): array
    {
        $context = new RepositoryReadContext;
        $names = array_values(array_filter($this->branches->names($project, $context), static fn (string $name): bool => mb_stripos($name, $query) !== false));
        usort($names, static fn (string $a, string $b): int => ($b === $project->default_branch) <=> ($a === $project->default_branch) ?: strcmp($a, $b));
        $names = array_slice($names, 0, self::Limit);
        $pullRequests = [];
        $unavailable = false;
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        if ($repository instanceof GitHubRepository && $names !== []) {
            $deadline = new ReadDeadline(10.0);
            try {
                if ($project->source_access === ProjectSourceAccess::GhCli) {
                    $token = $context->environment?->githubToken() ?? throw GitHubApiException::unavailable();
                } else {
                    [$repository, $credentials, $installation] = $context->installation ?? throw GitHubApiException::unavailable();
                    $token = $this->github->repositoryPullRequestReadToken($credentials, $installation, $repository, $deadline);
                }
                $pullRequests = $this->pulls->newestOpen($token, $repository, $deadline);
            } catch (Throwable) {
                $unavailable = true;
            }
        }
        $instances = Instance::query()->where('project_id', $project->id)->orderBy('id');
        if (! $this->access->hasGatewayAuthority($caller)) {
            $instances->whereIn('node_id', $this->access->accessibleNodeIds($caller));
        }
        $byBranch = [];
        foreach ($instances->get() as $instance) {
            $branch = $instance->branch ?? IntendedInstanceBranch::resolve($instance->name, $instance->branch_override, $project->default_branch);
            if ($branch !== null) {
                $byBranch[$branch] ??= new ConnBranchInstanceData($instance->id, $instance->name);
            }
        }
        $data = [];
        foreach ($names as $name) {
            $pull = $pullRequests[$name] ?? null;
            if ($pull !== null) {
                assert($pull->title !== null);
            }
            $data[] = new ConnProjectBranchData($name, $name === $project->default_branch,
                $pull === null ? null : new ConnBranchPullRequestData($pull->number, $pull->title, $pull->url), $byBranch[$name] ?? null);
        }

        return ['data' => $data, 'pull_requests_unavailable' => $unavailable];
    }
}
