<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\ReleaseManifestSource;
use RuntimeException;

final class ReleaseManifestSourceManager
{
    /**
     * @return array{url: string, source: string, custom_url: string|null, default_url: string}
     */
    public function update(string $url): array
    {
        $url = $this->normalizeCustomUrl($url);

        ReleaseManifestSource::current()->update([
            'custom_url' => $url,
        ]);

        return $this->payload();
    }

    /**
     * @return array{url: string, source: string, custom_url: string|null, default_url: string}
     */
    public function remove(): array
    {
        ReleaseManifestSource::current()->update([
            'custom_url' => null,
        ]);

        return $this->payload();
    }

    public function effectiveUrl(): string
    {
        return $this->customUrl() ?? $this->defaultUrl();
    }

    /**
     * @return array{url: string, source: string, custom_url: string|null, default_url: string}
     */
    public function payload(): array
    {
        $customUrl = $this->customUrl();
        $defaultUrl = $this->defaultUrl();

        return [
            'url' => $customUrl ?? $defaultUrl,
            'source' => $customUrl === null ? 'default' : 'custom',
            'custom_url' => $customUrl,
            'default_url' => $defaultUrl,
        ];
    }

    private function customUrl(): ?string
    {
        $source = ReleaseManifestSource::query()->first();

        if (! $source instanceof ReleaseManifestSource) {
            return null;
        }

        $url = is_string($source->custom_url) ? trim($source->custom_url) : '';

        return $url === '' ? null : $url;
    }

    private function defaultUrl(): string
    {
        $url = config('orbit.updates.release_manifest_url');

        if (! is_string($url) || trim($url) === '') {
            throw new RuntimeException('Release manifest URL is not configured.');
        }

        return trim($url);
    }

    private function normalizeCustomUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            throw new RuntimeException('Manifest URL is required.');
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw new RuntimeException('Manifest URL must be an http or https URL.');
        }

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Manifest URL must be a valid URL.');
        }

        return $url;
    }
}
