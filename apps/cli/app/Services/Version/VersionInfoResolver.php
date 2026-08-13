<?php

declare(strict_types=1);

namespace App\Services\Version;

final readonly class VersionInfoResolver
{
    private ReleaseMetadataResolver $releases;

    public function __construct(
        private InstallMetadataStore $installMetadata,
        ?ReleaseMetadataResolver $releases = null,
    ) {
        $this->releases = $releases ?? new ReleaseMetadataResolver;
    }

    public function resolve(): VersionInfo
    {
        $version = $this->currentVersion();
        $latestRelease = $this->releases->latest();
        $latestVersion = $latestRelease['version'] ?? null;
        $releasedAt = null;

        if (($latestRelease['version'] ?? null) === $version) {
            $releasedAt = $latestRelease['published_at'];
        }

        if ($latestVersion !== null && ! version_compare($latestVersion, $version, operator: '>')) {
            $latestVersion = $version;
        }

        if ($releasedAt === null) {
            $currentRelease = $this->releases->forVersion($version);
            $releasedAt = $currentRelease['published_at'] ?? null;
        }

        if ($releasedAt === null) {
            $releasedAt = $this->installMetadata->releasedAtFor($version);
        }

        return new VersionInfo(
            version: $version,
            latestVersion: $latestVersion,
            releasedAt: $releasedAt,
            installedAt: $this->installMetadata->installedAtFor($version),
            releaseSourceVersion: $latestRelease['version'] ?? null,
        );
    }

    public function resolveLocal(): VersionInfo
    {
        $version = $this->currentVersion();

        return new VersionInfo(
            version: $version,
            latestVersion: null,
            releasedAt: null,
            installedAt: $this->installMetadata->installedAtFor($version),
            releaseSourceVersion: null,
        );
    }

    private function currentVersion(): string
    {
        $version = config('app.version');

        if (is_string($version) && trim($version) !== '') {
            return trim($version);
        }

        return '0.0.0';
    }
}
