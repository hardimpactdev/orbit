<?php

declare(strict_types=1);

namespace App\Services\Version;

final readonly class ReleaseManifestParser
{
    private ReleaseTimestampParser $timestamps;

    public function __construct(?ReleaseTimestampParser $timestamps = null)
    {
        $this->timestamps = $timestamps ?? new ReleaseTimestampParser;
    }

    /**
     * @param  array<mixed>  $manifest
     * @return array{version: string, published_at: string|null}|null
     */
    public function parse(array $manifest): ?array
    {
        $version = $manifest['version'] ?? null;

        if (! is_string($version) || trim($version) === '') {
            return null;
        }

        return [
            'version' => ltrim(trim($version), characters: 'v'),
            'published_at' => $this->timestamps->parse(
                $manifest['released_at'] ?? null,
            ) ?? $this->timestamps->parseTopologyCandidateBuildId($manifest),
        ];
    }

    /**
     * @param  array<mixed>  $manifest
     * @return array{url: string, sha256: string}|null
     */
    public function parseCliArtifact(array $manifest, string $platform): ?array
    {
        $url = data_get($manifest, "cli_artifacts.{$platform}.url");
        $sha256 = data_get($manifest, "cli_artifacts.{$platform}.sha256");

        if (! is_string($url) || ! is_string($sha256)) {
            return null;
        }

        $url = trim($url);
        $sha256 = trim($sha256);

        if ($url === '' || preg_match('/^[a-f0-9]{64}$/i', $sha256) !== 1) {
            return null;
        }

        return [
            'url' => $url,
            'sha256' => strtolower($sha256),
        ];
    }
}
