<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GitHub\GitHubRepository;
use Illuminate\Support\Facades\Process;

/**
 * Where releases come from: the GitHub repository of the shared release repository's `origin`, the branch, and the
 * name of the check run that must pass ([Automatic releases](/reference/gateway-recovery#automatic-releases)). The
 * Gateway reads the origin from the shared repository on its own host.
 */
final readonly class GatewayReleaseSource
{
    public function __construct(
        public string $branch,
        public string $checkName,
        private ?GatewayReleaseLayout $layout = null,
    ) {}

    /** @throws GatewayReleaseException when the shared repository has no GitHub origin */
    public function repository(): GitHubRepository
    {
        $layout = $this->layout ?? GatewayReleaseLayout::fromConfig();
        $origin = Process::timeout(10)->run(['git', '--git-dir', $layout->repositoryPath(), 'config', '--get', 'remote.origin.url']);
        $repository = $origin->successful() ? GitHubRepository::fromOrigin(trim($origin->output())) : null;

        if (! $repository instanceof GitHubRepository) {
            throw new GatewayReleaseException(
                step: 'resolve',
                errorCode: 'gateway.release_source_unknown',
                message: 'The shared release repository has no github.com origin.',
                status: 500,
            );
        }

        return $repository;
    }

    /** The repository as `owner/name`, or null when it cannot be read. */
    public function repositoryName(): ?string
    {
        try {
            $repository = $this->repository();
        } catch (GatewayReleaseException) {
            return null;
        }

        return $repository->owner.'/'.$repository->name;
    }
}
