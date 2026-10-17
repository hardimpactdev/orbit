<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

/** A deploy moves forward in Git unless the operator explicitly overrides it. */
final readonly class GatewayReleaseGuard
{
    public function __construct(
        private GatewayReleaseLayout $layout,
        private ProcessRunner $processes,
    ) {}

    /** @return array{source: string|null, source_sha: string|null} */
    public function source(): array
    {
        $id = $this->layout->currentReleaseId();

        return ['source' => $id, 'source_sha' => $id === null ? null : $this->layout->preparedCommit($id)];
    }

    /** @throws GatewayReleaseException */
    public function assertForward(string $sha, bool $force): void
    {
        $current = $this->layout->currentReleaseId();
        $currentSha = $current === null ? null : $this->layout->preparedCommit($current);

        if ($force || $currentSha === null || $currentSha === $sha) {
            return;
        }

        $result = $this->processes->run(new ProcessInvocation(
            ['git', '-C', $this->layout->repositoryPath(), 'merge-base', '--is-ancestor', $currentSha, $sha],
            timeout: 30.0,
        ));

        if ($result->succeeded()) {
            return;
        }

        throw new GatewayReleaseException(
            step: 'guard',
            errorCode: 'gateway.release_downgrade',
            message: "Commit [{$sha}] does not descend from the current release [{$currentSha}]. Use gateway:release:rollback, or pass --force.",
            status: 409,
            sha: $sha,
        );
    }
}
