<?php

declare(strict_types=1);

namespace App\Infrastructure\GitHub;

use App\Domain\GitHub\GitHubRepository;
use App\Domain\Projects\CiCacheArchives;
use App\Domain\Projects\CiCacheSource;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;

/**
 * Reads the main caches that `ci.yml` publishes on the Project's default branch: the `sandbox-tia-<job>-<commit>`
 * artifacts of the newest successful run that has them. It reads the newest {@see self::Runs} successful runs from a
 * push, a schedule, or a manual dispatch. Pull request runs publish no main caches.
 */
final readonly class HttpGitHubCiCaches implements CiCacheSource
{
    public const string Workflow = 'ci.yml';

    public const int Runs = 20;

    public function __construct(private GitHubActionsReader $actions) {}

    public function fetch(Project $project): CiCacheArchives
    {
        $repository = GitHubRepository::fromOrigin($project->repository_url);
        $branch = $project->default_branch;
        if ($repository === null || ! is_string($branch) || $branch === '') {
            throw $this->unavailable();
        }

        try {
            return $this->download($repository, $branch) ?? throw new ResourceOperationException(
                'instance.ci_caches_missing',
                'No successful '.self::Workflow.' run among the newest '.self::Runs." on {$branch} has unexpired main caches.",
                422,
            );
        } catch (GitHubActionsUnavailable) {
            throw $this->unavailable();
        }
    }

    private function download(GitHubRepository $repository, string $branch): ?CiCacheArchives
    {
        $path = GitHubActionsReader::repositoryPath($repository);
        $token = $this->actions->token($repository);
        $runs = $this->actions->json($path.'/actions/workflows/'.self::Workflow.'/runs?'.http_build_query([
            'branch' => $branch, 'status' => 'success', 'per_page' => self::Runs,
        ]), $token)['workflow_runs'] ?? null;
        if (! is_array($runs) || count($runs) > self::Runs) {
            throw new GitHubActionsUnavailable;
        }
        foreach ($runs as $run) {
            if (! is_array($run) || ($run['head_branch'] ?? null) !== $branch
                || ($run['status'] ?? null) !== 'completed' || ($run['conclusion'] ?? null) !== 'success'
                || ! in_array($run['event'] ?? null, ['push', 'schedule', 'workflow_dispatch'], true)
                || ! is_int($run['id'] ?? null) || $run['id'] < 1
                || ! is_string($run['head_sha'] ?? null) || preg_match('/\A[0-9a-f]{40}\z/D', $run['head_sha']) !== 1) {
                continue;
            }
            $listing = $this->actions->json($path.'/actions/runs/'.$run['id'].'/artifacts?per_page=100', $token);
            $rows = $listing['artifacts'] ?? null;
            if (! is_array($rows) || ! is_int($listing['total_count'] ?? null) || $listing['total_count'] > 100) {
                throw new GitHubActionsUnavailable;
            }
            $artifacts = [];
            foreach ($rows as $artifact) {
                if (! is_array($artifact) || ! is_string($artifact['name'] ?? null)
                    || preg_match('/\Asandbox-tia-[0-9]{1,3}-'.$run['head_sha'].'\z/D', $artifact['name']) !== 1) {
                    continue;
                }
                $provenance = $artifact['workflow_run'] ?? null;
                if (($artifact['expired'] ?? null) !== false || ! is_array($provenance) || ! is_int($artifact['id'] ?? null) || $artifact['id'] < 1
                    || ! is_int($artifact['size_in_bytes'] ?? null) || $artifact['size_in_bytes'] < 1
                    || $artifact['size_in_bytes'] > CiCacheArchives::MaxBytes
                    || ($provenance['id'] ?? null) !== $run['id'] || ($provenance['head_sha'] ?? null) !== $run['head_sha']) {
                    throw new GitHubActionsUnavailable;
                }
                $artifacts[] = $artifact['id'];
            }
            if ($artifacts === []) {
                continue;
            }
            if (count($artifacts) > CiCacheArchives::MaxArchives) {
                throw new GitHubActionsUnavailable;
            }
            sort($artifacts);
            $archives = array_map(fn (int $id): string => $this->actions->body(
                $this->actions->archiveLocation($repository, $id, $token), null, CiCacheArchives::MaxBytes, timeout: 60.0,
            ), $artifacts);
            if (array_sum(array_map(strlen(...), $archives)) > CiCacheArchives::MaxTotalBytes) {
                throw new GitHubActionsUnavailable;
            }

            return new CiCacheArchives($run['head_sha'], $run['id'], $archives);
        }

        return null;
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException('instance.ci_caches_unavailable', 'Cannot read the CI main caches. Install the Gateway GitHub App on this repository and accept Actions read permission.', 422);
    }
}
