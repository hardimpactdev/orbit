<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GitHub\GitHubRepository;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;

/**
 * Selects the CI artifact `web-dist-<sha>` for a release; the release run downloads, checks and installs it on the
 * Gateway Node.
 *
 * CI's `Required checks` job needs the Web job, and the Web job uploads the artifact before it
 * succeeds, in the merge queue run as well as on `main`. A commit whose checks passed therefore has
 * the artifact, so a missing or expired one fails the commit instead of waiting for it. GitHub that
 * cannot be read is retried later.
 */
final readonly class GitHubArtifactWebBuild
{
    /** The largest archive a Swamp release downloads: 128 MiB, the agent install's limit. */
    public const int ArchiveLimit = 134217728;

    public function __construct(
        private GatewayReleaseSource $source,
        private GitHubActionsReader $actions,
        private string $branch = 'main',
        private string $workflow = '.github/workflows/ci.yml',
    ) {}

    public static function artifactName(string $sha): string
    {
        return 'web-dist-'.$sha;
    }

    private function repository(): GitHubRepository
    {
        try {
            $repository = $this->source->repository();
        } catch (GatewayReleaseException) {
            $repository = null;
        }

        if (! $repository instanceof GitHubRepository) {
            throw new GatewayReleaseException(
                step: 'web',
                errorCode: 'gateway.release_web_build_unavailable',
                message: 'The shared release repository has no github.com origin, so its web build cannot be found.',
                status: 500,
            );
        }

        return $repository;
    }

    /**
     * The newest unexpired `web-dist-<sha>` artifact from an accepted CI run of exactly this commit, with its digest.
     * The release run downloads it on the Gateway Node.
     *
     * @return array{repository: string, artifact_id: int, size: int, digest: string}
     */
    public function select(string $sha, int $archiveLimit = self::ArchiveLimit): array
    {
        $repository = $this->repository();
        $selected = $this->selectArtifact($repository, $sha, $archiveLimit);

        return ['repository' => $repository->owner.'/'.$repository->name, 'artifact_id' => $selected['id'], 'size' => $selected['size'], 'digest' => $selected['digest']];
    }

    /** @return array{id: int, size: int, digest: string, token: string} */
    private function selectArtifact(GitHubRepository $repository, string $sha, int $archiveLimit): array
    {
        $name = self::artifactName($sha);
        $path = GitHubActionsReader::repositoryPath($repository);

        try {
            $token = $this->actions->token($repository);
            $listing = $this->actions->json($path.'/actions/artifacts?'.http_build_query(['name' => $name, 'per_page' => 100]), $token);
            $rows = $listing['artifacts'] ?? null;

            if (! is_array($rows) || ! is_int($listing['total_count'] ?? null)) {
                throw new GitHubActionsUnavailable;
            }

            $expired = false;

            foreach ($this->newestFirst($rows) as $artifact) {
                if (($artifact['name'] ?? null) !== $name) {
                    continue;
                }

                if (($artifact['expired'] ?? null) !== false) {
                    $expired = true;

                    continue;
                }

                $id = $artifact['id'] ?? null;
                $size = $artifact['size_in_bytes'] ?? null;
                $run = $artifact['workflow_run'] ?? null;

                if (! is_int($id) || $id < 1 || ! is_int($size) || ! is_array($run)
                    || ($run['head_sha'] ?? null) !== $sha || ! is_int($run['id'] ?? null)
                    || ! is_int($run['repository_id'] ?? null) || ($run['head_repository_id'] ?? null) !== $run['repository_id']
                    || ! is_string($run['head_branch'] ?? null) || ! $this->releaseBranch($run['head_branch'])
                    || ! $this->ciRun($path, $run['id'], $sha, $run['repository_id'], $token)) {
                    continue;
                }

                $expected = $artifact['digest'] ?? null;

                if (! is_string($expected) || preg_match('/\Asha256:[0-9a-f]{64}\z/D', strtolower($expected)) !== 1) {
                    throw $this->invalid("The web build artifact [{$name}] has no SHA-256 digest, so its download cannot be checked.");
                }

                if ($size < 1 || $size > $archiveLimit) {
                    throw $this->invalid("The web build artifact [{$name}] has {$size} bytes; at most ".$archiveLimit.' are accepted.');
                }

                return ['id' => $id, 'size' => $size, 'digest' => strtolower($expected), 'token' => $token];
            }
        } catch (GitHubActionsUnavailable) {
            throw $this->unavailable($sha);
        }

        throw new GatewayReleaseException(
            step: 'web',
            errorCode: 'gateway.release_web_build_missing',
            message: $expired
                ? "The CI artifact [{$name}] has expired. Release a newer commit."
                : "CI published no artifact [{$name}] from a run of {$this->workflow} on {$this->branch} for this commit. Release a commit whose CI run uploaded its web build.",
            status: 422,
            sha: $sha,
        );
    }

    private function unavailable(string $sha): GatewayReleaseException
    {
        return new GatewayReleaseException(
            step: 'web',
            errorCode: 'gateway.release_web_build_unavailable',
            message: 'Cannot read the web build from GitHub Actions. Install the Gateway GitHub App on the repository with Actions read permission.',
            status: 500,
            sha: $sha,
        );
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @return list<array<array-key, mixed>>
     */
    private function newestFirst(array $rows): array
    {
        $artifacts = array_values(array_filter($rows, is_array(...)));
        usort($artifacts, static fn (array $left, array $right): int => [(string) ($right['created_at'] ?? ''), $right['id'] ?? 0] <=> [(string) ($left['created_at'] ?? ''), $left['id'] ?? 0]);

        return $artifacts;
    }

    /**
     * Only a run of the CI workflow on the release branch of this repository counts, for exactly this commit: a push or
     * a manual dispatch on the branch, or the merge queue run whose tested commit the queue then puts on the branch. A
     * pull request from a fork runs in this repository too, but its run has another event, branch, or head repository.
     */
    private function ciRun(string $path, int $runId, string $sha, int $repositoryId, string $token): bool
    {
        $run = $this->actions->json($path.'/actions/runs/'.$runId, $token);
        $repository = $run['repository'] ?? null;
        $head = $run['head_repository'] ?? null;

        $event = $run['event'] ?? null;
        $branch = $run['head_branch'] ?? null;
        $accepted = is_string($branch) && match ($event) {
            'push', 'workflow_dispatch' => $branch === $this->branch,
            'merge_group' => $this->queueBranch($branch),
            default => false,
        };

        return ($run['id'] ?? null) === $runId
            && $accepted
            && ($run['path'] ?? null) === $this->workflow
            && ($run['head_sha'] ?? null) === $sha
            && is_array($repository) && ($repository['id'] ?? null) === $repositoryId
            && is_array($head) && ($head['id'] ?? null) === $repositoryId;
    }

    /** The release branch itself, or one of its merge queue branches. */
    private function releaseBranch(string $branch): bool
    {
        return $branch === $this->branch || $this->queueBranch($branch);
    }

    /** A branch GitHub's merge queue creates to test a commit before it puts it on the release branch. */
    private function queueBranch(string $branch): bool
    {
        return str_starts_with($branch, 'gh-readonly-queue/'.$this->branch.'/');
    }

    private function invalid(string $message): GatewayReleaseException
    {
        return new GatewayReleaseException(step: 'web', errorCode: 'gateway.release_web_build_invalid', message: $message, status: 422);
    }
}
