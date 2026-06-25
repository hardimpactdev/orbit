<?php

declare(strict_types=1);

namespace App\Services\Version;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class VersionInfoResolver
{
    private const string LatestManifestUrl = 'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json';

    private const string VersionManifestUrl = 'https://github.com/hardimpactdev/orbit/releases/download/v%s/orbit-release-manifest.json';

    private const string ReleasesApi = 'https://api.github.com/repos/hardimpactdev/orbit/releases';

    public function __construct(
        private InstallMetadataStore $installMetadata,
    ) {}

    public function resolve(): VersionInfo
    {
        $version = $this->currentVersion();
        $latestRelease =
            $this->configuredReleaseManifest() ?? $this->releaseManifest(
                self::LatestManifestUrl,
            ) ?? $this->apiRelease('latest');
        $latestVersion = $latestRelease['version'] ?? null;
        $releasedAt = null;

        if (($latestRelease['version'] ?? null) === $version) {
            $releasedAt = $latestRelease['published_at'];
        }

        if ($latestVersion !== null && ! version_compare($latestVersion, $version, '>')) {
            $latestVersion = $version;
        }

        if ($releasedAt === null) {
            $currentRelease =
                $this->configuredReleaseManifestFor($version) ?? $this->releaseManifest(sprintf(
                    self::VersionManifestUrl,
                    $version,
                )) ?? $this->apiRelease("tags/v{$version}");
            $releasedAt = $currentRelease['published_at'] ?? null;
        }

        if ($releasedAt === null) {
            $releasedAt = $this->installMetadata->releasedAtFor($version);
        }

        return new VersionInfo(
            version: $version,
            latestVersion: $latestVersion,
            updateAvailable: $latestVersion !== null && version_compare($latestVersion, $version, '>'),
            releasedAt: $releasedAt,
            installedAt: $this->installMetadata->installedAtFor($version),
        );
    }

    public function resolveLocal(): VersionInfo
    {
        $version = $this->currentVersion();

        return new VersionInfo(
            version: $version,
            latestVersion: null,
            updateAvailable: false,
            releasedAt: null,
            installedAt: $this->installMetadata->installedAtFor($version),
        );
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    private function releaseManifest(string $url): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(2)
                ->get($url);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $version = $body['version'] ?? null;

        if (! is_string($version) || trim($version) === '') {
            return null;
        }

        return [
            'version' => ltrim(trim($version), 'v'),
            'published_at' => $this->timestamp($body['released_at'] ?? null) ?? $this->topologyCandidateBuildTimestamp(
                $body,
            ),
        ];
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    private function configuredReleaseManifestFor(string $version): ?array
    {
        $manifest = $this->configuredReleaseManifest();

        if (($manifest['version'] ?? null) !== $version) {
            return null;
        }

        return $manifest;
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    private function configuredReleaseManifest(): ?array
    {
        $url = $this->configuredReleaseManifestUrl();

        return $url === null ? null : $this->releaseManifest($url);
    }

    private function configuredReleaseManifestUrl(): ?string
    {
        $value = getenv('ORBIT_RELEASE_MANIFEST_URL');

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    private function apiRelease(string $selector): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(2)
                ->get(self::ReleasesApi.'/'.$selector);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! is_array($body)) {
            return null;
        }

        $tag = $body['tag_name'] ?? null;
        $publishedAt = $body['published_at'] ?? null;

        if (! is_string($tag) || ! is_string($publishedAt)) {
            return null;
        }

        $publishedAt = $this->timestamp($publishedAt);

        if ($publishedAt === null) {
            return null;
        }

        return [
            'version' => ltrim($tag, 'v'),
            'published_at' => $publishedAt,
        ];
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toIso8601String();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<mixed>  $manifest
     */
    private function topologyCandidateBuildTimestamp(array $manifest): ?string
    {
        if (($manifest['source'] ?? null) !== 'topology-candidate') {
            return null;
        }

        $buildId = $manifest['build_id'] ?? null;

        if (! is_string($buildId)) {
            return null;
        }

        if (! preg_match(
            '/^(?<date>\d{4}-?\d{2}-?\d{2})T(?<hour>\d{2})(?<minute>\d{2})(?<second>\d{2})Z(?:-|$)/',
            $buildId,
            $matches,
        )) {
            return null;
        }

        $date = str_replace('-', '', $matches['date']);
        $date = substr($date, 0, 4).'-'.substr($date, 4, 2).'-'.substr($date, 6, 2);

        return $this->timestamp("{$date}T{$matches['hour']}:{$matches['minute']}:{$matches['second']}Z");
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
