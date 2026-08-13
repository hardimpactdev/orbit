<?php

declare(strict_types=1);

namespace App\Services\Version;

use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class ReleaseManifestResolver
{
    private const string LATEST_MANIFEST_URL = 'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json';

    private const string VERSION_MANIFEST_URL = 'https://github.com/hardimpactdev/orbit/releases/download/v%s/orbit-release-manifest.json';

    private ReleaseManifestParser $parser;

    public function __construct(?ReleaseManifestParser $parser = null)
    {
        $this->parser = $parser ?? new ReleaseManifestParser;
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    public function latest(): ?array
    {
        return $this->releaseManifest(self::LATEST_MANIFEST_URL);
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    public function forVersion(string $version): ?array
    {
        return $this->releaseManifest(sprintf(self::VERSION_MANIFEST_URL, $version));
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    public function configuredForVersion(string $version): ?array
    {
        $manifest = $this->configured();

        if (($manifest['version'] ?? null) !== $version) {
            return null;
        }

        return $manifest;
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    public function configured(): ?array
    {
        $url = $this->configuredUrl();

        return $url === null ? null : $this->releaseManifest($url);
    }

    /**
     * @return array{url: string, sha256: string}|null
     */
    public function configuredCliArtifact(string $platform): ?array
    {
        $url = $this->configuredUrl();

        if ($url === null) {
            return null;
        }

        $manifest = $this->releaseManifestBody($url);

        return $manifest === null ? null : $this->parser->parseCliArtifact($manifest, $platform);
    }

    private function configuredUrl(): ?string
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
    private function releaseManifest(string $url): ?array
    {
        $body = $this->releaseManifestBody($url);

        return $body === null ? null : $this->parser->parse($body);
    }

    /**
     * @return array<mixed>|null
     */
    private function releaseManifestBody(string $url): ?array
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

        return is_array($body) ? $body : null;
    }
}
