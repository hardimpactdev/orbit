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
        $latestRelease = $this->releaseManifest(self::LatestManifestUrl) ?? $this->apiRelease('latest');
        $latestVersion = $latestRelease['version'] ?? null;
        $releasedAt = null;

        if (($latestRelease['version'] ?? null) === $version) {
            $releasedAt = $latestRelease['published_at'];
        }

        if ($releasedAt === null) {
            $currentRelease = $this->releaseManifest(sprintf(self::VersionManifestUrl, $version))
                ?? $this->apiRelease("tags/v{$version}");
            $releasedAt = $currentRelease['published_at'] ?? null;
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
            'published_at' => $this->timestamp($body['released_at'] ?? null),
        ];
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

    private function currentVersion(): string
    {
        $version = config('app.version');

        if (is_string($version) && trim($version) !== '') {
            return trim($version);
        }

        return '0.0.0';
    }
}
