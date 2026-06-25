<?php

declare(strict_types=1);

namespace App\Services\Version;

final readonly class ReleaseMetadataResolver
{
    private ReleaseManifestResolver $manifests;

    private GitHubReleaseResolver $github;

    public function __construct(
        ?ReleaseManifestResolver $manifests = null,
        ?GitHubReleaseResolver $github = null,
    ) {
        $this->manifests = $manifests ?? new ReleaseManifestResolver;
        $this->github = $github ?? new GitHubReleaseResolver;
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    public function latest(): ?array
    {
        return $this->manifests->configured() ?? $this->manifests->latest() ?? $this->github->release('latest');
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    public function forVersion(string $version): ?array
    {
        return (
            $this->manifests->configuredForVersion($version) ?? $this->manifests->forVersion(
                $version,
            ) ?? $this->github->release("tags/v{$version}")
        );
    }
}
