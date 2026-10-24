<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\GitHubRepository;
use App\Infrastructure\GitHub\GitHubActionsReader;
use App\Infrastructure\GitHub\GitHubActionsUnavailable;

/**
 * Selects and downloads the CI artifact `web-dist-<sha>`. Named agent commands own its
 * validation, installation, publication, and retention on the Gateway Node.
 *
 * CI's `Required checks` job needs the Web job, and the Web job uploads the artifact before it
 * succeeds. A commit whose checks passed therefore has the artifact, so a missing or expired one
 * fails the commit instead of waiting for it. GitHub that cannot be read is retried later.
 */
final class GitHubArtifactWebBuild implements GatewayReleaseWebBuild
{
    /** Whether this attempt switched `current`, and the link target it replaced. */
    private bool $published = false;

    private ?string $publishedFrom = null;

    public function __construct(
        private readonly GatewayReleaseLayout $layout,
        private readonly GatewayReleaseCommands $commands,
        private readonly GitHubActionsReader $actions,
        private readonly string $webRoot,
        private readonly string $branch = 'main',
        private readonly string $workflow = '.github/workflows/ci.yml',
    ) {}

    public static function artifactName(string $sha): string
    {
        return 'web-dist-'.$sha;
    }

    public function install(string $id, string $sha): bool
    {
        $id = GatewayReleaseCommit::assertId($id);

        if (strlen($sha) !== 40 || ! GatewayReleaseCommit::isSha($sha) || ! str_starts_with($sha, $id)) {
            throw $this->invalid("Web build [{$id}] needs the full commit, not [{$sha}].");
        }

        $inspection = $this->commands->inspectWeb($this->webRoot, $id);

        if ($inspection['installed']) {
            return true;
        }

        $repository = $this->repository();
        $archive = @tempnam(sys_get_temp_dir(), 'orbit-web-build-');

        if ($archive === false) {
            throw $this->installFailed('The web build archive cannot be created in the temporary directory.');
        }

        try {
            if (! chmod($archive, 0600)) {
                throw $this->installFailed('The downloaded web archive cannot be made private.');
            }
            $digest = $this->downloadArtifact($repository, $sha, $archive, $inspection['archive_limit']);

            return $this->commands->installWeb($this->webRoot, $sha, $archive, $digest);
        } finally {
            @unlink($archive);
        }
    }

    public function publish(string $id): void
    {
        $previous = $this->commands->publishWeb($this->webRoot, GatewayReleaseCommit::assertId($id));
        $this->published = true;
        $this->publishedFrom = $previous;
    }

    public function restore(string $id): void
    {
        if (! $this->published) {
            return;
        }

        $this->commands->restoreWeb($this->webRoot, GatewayReleaseCommit::assertId($id), $this->publishedFrom);
        $this->published = false;
        $this->publishedFrom = null;
    }

    public function remove(string $id): void
    {
        $this->commands->removeWeb($this->webRoot, GatewayReleaseCommit::assertId($id));
    }

    public function prune(array $retained): void
    {
        // An empty list means release inventory was unavailable.
        if ($retained !== []) {
            $this->commands->pruneWeb($this->webRoot, $retained);
        }
    }

    private function repository(): GitHubRepository
    {
        try {
            $repository = GitHubRepository::fromOrigin($this->commands->origin($this->layout));
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
     * Finds the newest unexpired `web-dist-<sha>` artifact from a CI run on the release branch for exactly this
     * commit, downloads it, and checks it against its required digest.
     */
    private function downloadArtifact(GitHubRepository $repository, string $sha, string $archive, int $archiveLimit): string
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
                    || ($run['head_branch'] ?? null) !== $this->branch
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

                $digest = $this->actions->download($this->actions->archiveLocation($repository, $id, $token), $archive, $archiveLimit);

                if (! hash_equals(strtolower($expected), 'sha256:'.$digest)) {
                    throw $this->invalid("The downloaded web build [{$name}] does not match its artifact digest.");
                }

                return $digest;
            }
        } catch (GitHubActionsUnavailable) {
            throw new GatewayReleaseException(
                step: 'web',
                errorCode: 'gateway.release_web_build_unavailable',
                message: 'Cannot read the web build from GitHub Actions. Install the Gateway GitHub App on the repository with Actions read permission.',
                status: 500,
                sha: $sha,
            );
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
     * Only a run of the CI workflow on the release branch of this repository counts: a push, or a manual dispatch, for
     * exactly this commit. A pull request from a fork runs in this repository too, but its run has another event, branch,
     * or head repository.
     */
    private function ciRun(string $path, int $runId, string $sha, int $repositoryId, string $token): bool
    {
        $run = $this->actions->json($path.'/actions/runs/'.$runId, $token);
        $repository = $run['repository'] ?? null;
        $head = $run['head_repository'] ?? null;

        return ($run['id'] ?? null) === $runId
            && in_array($run['event'] ?? null, ['push', 'workflow_dispatch'], true)
            && ($run['head_branch'] ?? null) === $this->branch
            && ($run['path'] ?? null) === $this->workflow
            && ($run['head_sha'] ?? null) === $sha
            && is_array($repository) && ($repository['id'] ?? null) === $repositoryId
            && is_array($head) && ($head['id'] ?? null) === $repositoryId;
    }

    private function invalid(string $message): GatewayReleaseException
    {
        return new GatewayReleaseException(step: 'web', errorCode: 'gateway.release_web_build_invalid', message: $message, status: 422);
    }

    private function installFailed(string $message): GatewayReleaseException
    {
        return new GatewayReleaseException(step: 'web', errorCode: 'gateway.release_web_install_failed', message: $message, status: 500);
    }
}
