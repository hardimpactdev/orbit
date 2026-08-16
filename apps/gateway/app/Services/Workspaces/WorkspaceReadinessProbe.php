<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Throwable;

final readonly class WorkspaceReadinessProbe
{
    public function __construct(
        private int $maxAttempts = 10,
        private int $retryDelayMilliseconds = 1_000,
        private WorkspacePlacement $placement = new WorkspacePlacement,
    ) {}

    /**
     * @return array{url: string, result: 'healthy'|'unhealthy', status_code: int|null, duration_ms: int}
     */
    public function probe(Workspace $workspace): array
    {
        $url = $workspace->url();

        if ($this->maxAttempts <= 0) {
            return $this->result($url, 'unhealthy', null, 0);
        }

        $startedAt = (int) hrtime(true);
        $result = $this->result($url, 'unhealthy', null, 0);

        for ($attemptNumber = 1; $attemptNumber <= $this->maxAttempts; $attemptNumber++) {
            $attempt = $this->probeOnce($workspace, $url);
            $result = $attempt['result'];

            if ($result['result'] === 'healthy' || ! $attempt['retryable']) {
                break;
            }

            if ($attemptNumber < $this->maxAttempts && $this->retryDelayMilliseconds > 0) {
                usleep($this->retryDelayMilliseconds * 1_000);
            }
        }

        $result['duration_ms'] = $this->durationMilliseconds($startedAt);

        return $result;
    }

    /**
     * @param  callable(): array{url: string, result: 'healthy'|'unhealthy', status_code: int|null, duration_ms: int}  $attempt
     * @return array{url: string, result: 'healthy'|'unhealthy', status_code: int|null, duration_ms: int}
     */
    public function probeWith(callable $attempt): array
    {
        $result = $this->result('', 'unhealthy', null, 0);

        for ($attemptNumber = 1; $attemptNumber <= $this->maxAttempts; $attemptNumber++) {
            $result = $attempt();

            if ($result['result'] === 'healthy' || ! $this->shouldRetry($result)) {
                return $result;
            }

            if ($attemptNumber < $this->maxAttempts && $this->retryDelayMilliseconds > 0) {
                usleep($this->retryDelayMilliseconds * 1_000);
            }
        }

        return $result;
    }

    /**
     * @return array{
     *     result: array{url: string, result: 'healthy'|'unhealthy', status_code: int|null, duration_ms: int},
     *     retryable: bool,
     * }
     */
    private function probeOnce(Workspace $workspace, string $url): array
    {
        $workspace->loadMissing(['app']);

        $app = $workspace->app;

        if (! $app instanceof App) {
            return ['result' => $this->result($url, 'unhealthy'), 'retryable' => false];
        }

        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $node instanceof Node) {
            return ['result' => $this->result($url, 'unhealthy'), 'retryable' => false];
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->withoutVerifying()
                ->get($url);
        } catch (Throwable $exception) {
            report($exception);

            return ['result' => $this->result($url, 'unhealthy'), 'retryable' => true];
        }

        $statusCode = $response->status();
        if ($statusCode >= 500) {
            return [
                'result' => $this->result($url, 'unhealthy', $statusCode),
                'retryable' => true,
            ];
        }

        $assetStatus = $this->probeViteAssets($url, $response->body());

        if ($assetStatus !== null) {
            return $assetStatus;
        }

        return [
            'result' => $this->result($url, 'healthy', $statusCode),
            'retryable' => false,
        ];
    }

    /**
     * @return array{
     *     result: array{url: string, result: 'unhealthy', status_code: int|null, duration_ms: int},
     *     retryable: true,
     * }|null
     */
    private function probeViteAssets(string $baseUrl, string $html): ?array
    {
        foreach ($this->viteAssetUrls($baseUrl, $html) as $assetUrl) {
            try {
                $response = Http::timeout(10)
                    ->connectTimeout(5)
                    ->withoutVerifying()
                    ->get($assetUrl);
            } catch (Throwable $exception) {
                report($exception);

                return [
                    'result' => [
                        'url' => $baseUrl,
                        'result' => 'unhealthy',
                        'status_code' => null,
                        'duration_ms' => 0,
                    ],
                    'retryable' => true,
                ];
            }

            if ($response->status() >= 400) {
                return [
                    'result' => [
                        'url' => $baseUrl,
                        'result' => 'unhealthy',
                        'status_code' => $response->status(),
                        'duration_ms' => 0,
                    ],
                    'retryable' => true,
                ];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function viteAssetUrls(string $baseUrl, string $html): array
    {
        preg_match_all('/<script\b[^>]*\btype=["\']module["\'][^>]*\bsrc=["\']([^"\']+)["\'][^>]*>/i', $html, $matches);

        $urls = [];

        foreach ($matches[1] as $src) {
            $url = $this->absoluteUrl($baseUrl, $src);
            $path = parse_url($url, PHP_URL_PATH) ?: '';

            if (
                str_starts_with($path, '/@vite/')
                || str_starts_with($path, '/@react-refresh')
                || str_starts_with($path, '/resources/')
            ) {
                $urls[] = $url;
            }
        }

        return array_values(array_unique($urls));
    }

    private function absoluteUrl(string $baseUrl, string $src): string
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($baseUrl, PHP_URL_HOST) ?: '';
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $authority = $host.($port !== null ? ":{$port}" : '');

        return "{$scheme}://{$authority}/".ltrim($src, '/');
    }

    /**
     * @param  array{url: string, result: 'healthy'|'unhealthy', status_code: int|null, duration_ms: int}  $result
     */
    private function shouldRetry(array $result): bool
    {
        return $result['status_code'] === null || $result['status_code'] >= 500;
    }

    /**
     * @param  'healthy'|'unhealthy'  $result
     * @return array{url: string, result: 'healthy'|'unhealthy', status_code: int|null, duration_ms: int}
     */
    private function result(
        string $url,
        string $result,
        ?int $statusCode = null,
        int $durationMilliseconds = 0,
    ): array {
        return [
            'url' => $url,
            'result' => $result,
            'status_code' => $statusCode,
            'duration_ms' => $durationMilliseconds,
        ];
    }

    private function durationMilliseconds(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
