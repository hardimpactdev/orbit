<?php

declare(strict_types=1);

namespace App\Services\Version;

use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class GitHubReleaseResolver
{
    private const string RELEASES_API = 'https://api.github.com/repos/hardimpactdev/orbit/releases';

    private ReleaseTimestampParser $timestamps;

    public function __construct(?ReleaseTimestampParser $timestamps = null)
    {
        $this->timestamps = $timestamps ?? new ReleaseTimestampParser;
    }

    /**
     * @return array{version: string, published_at: string|null}|null
     */
    public function release(string $selector): ?array
    {
        try {
            $response = Http::acceptJson()
                ->timeout(2)
                ->get(self::RELEASES_API.'/'.$selector);
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

        $publishedAt = $this->timestamps->parse($publishedAt);

        if ($publishedAt === null) {
            return null;
        }

        return [
            'version' => ltrim($tag, characters: 'v'),
            'published_at' => $publishedAt,
        ];
    }
}
