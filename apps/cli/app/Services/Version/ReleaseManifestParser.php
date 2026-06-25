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
}
