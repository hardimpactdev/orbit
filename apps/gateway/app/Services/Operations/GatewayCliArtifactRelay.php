<?php

declare(strict_types=1);

namespace App\Services\Operations;

use App\Models\Node;
use App\Models\OperationRun;
use App\Models\OperationUpdatePlan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class GatewayCliArtifactRelay
{
    public function __construct(
        private readonly ?FleetUpdateTargetSelector $targets = null,
    ) {}

    /**
     * @return array{url: string, sha256: string, source_url: string}
     */
    public function artifactFor(OperationRun $operationRun, OperationUpdatePlan $plan, string $platform): array
    {
        $artifact = $this->manifestArtifact($plan, $platform);

        $this->stageArtifact($operationRun, $platform, $artifact);

        return [
            'url' => $this->downloadUrl($operationRun, $platform, $artifact['sha256']),
            'sha256' => $artifact['sha256'],
            'source_url' => $artifact['url'],
        ];
    }

    public function stage(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $this->cleanupExpired();

        foreach (array_keys($plan->cli_artifacts) as $platform) {
            if (! is_string($platform)) {
                continue;
            }

            $this->stageArtifact($operationRun, $platform, $this->manifestArtifact($plan, $platform));
        }
    }

    public function cleanup(OperationRun $operationRun): void
    {
        File::deleteDirectory($this->operationDirectory($operationRun));
    }

    public function cleanupExpired(): void
    {
        $baseDirectory = $this->baseDirectory();

        if (! File::isDirectory($baseDirectory)) {
            return;
        }

        $expiresBefore = time() - $this->cacheTtlSeconds();

        foreach (File::directories($baseDirectory) as $directory) {
            if (@filemtime($directory) !== false && filemtime($directory) < $expiresBefore) {
                File::deleteDirectory($directory);
            }
        }
    }

    public function downloadPath(OperationRun $operationRun, string $platform, ?string $token): string
    {
        $plan = $operationRun->updatePlan()->first();

        if (! $plan instanceof OperationUpdatePlan) {
            throw new RuntimeException('The update plan for this operation was not found.');
        }

        $artifact = $this->manifestArtifact($plan, $platform);

        if (! $this->tokenMatches($operationRun, $platform, $artifact['sha256'], $token)) {
            throw new RuntimeException('The update artifact token is invalid.');
        }

        $path = $this->artifactPath($operationRun, $platform);

        if (! File::isFile($path)) {
            throw new RuntimeException('The update artifact has not been staged on this gateway.');
        }

        $this->assertHashMatches($path, $artifact['sha256']);

        return $path;
    }

    /**
     * @param  array{url: string, sha256: string}  $artifact
     */
    private function stageArtifact(OperationRun $operationRun, string $platform, array $artifact): void
    {
        $path = $this->artifactPath($operationRun, $platform);

        if (File::isFile($path) && hash_file('sha256', $path) === strtolower($artifact['sha256'])) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));

        $temporaryPath = $path.'.tmp-'.Str::random(8);

        try {
            $response = Http::timeout($this->downloadTimeoutSeconds())->get($artifact['url']);

            if (! $response->successful()) {
                throw new RuntimeException("Could not download CLI artifact [{$artifact['url']}].");
            }

            File::put($temporaryPath, $response->body());
            $this->assertHashMatches($temporaryPath, $artifact['sha256']);
            File::delete($path);
            File::move($temporaryPath, $path);
        } finally {
            File::delete($temporaryPath);
        }
    }

    /**
     * @return array{url: string, sha256: string}
     */
    private function manifestArtifact(OperationUpdatePlan $plan, string $platform): array
    {
        $this->assertSafePlatform($platform);

        $artifact = $plan->cli_artifacts[$platform] ?? null;

        if (
            ! is_array($artifact)
            || ! is_string($artifact['url'] ?? null)
            || ! is_string($artifact['sha256'] ?? null)
        ) {
            throw new RuntimeException("Update plan does not contain a CLI artifact for platform [{$platform}].");
        }

        return [
            'url' => $artifact['url'],
            'sha256' => strtolower($artifact['sha256']),
        ];
    }

    private function downloadUrl(OperationRun $operationRun, string $platform, string $sha256): string
    {
        $baseUrl = $this->downloadBaseUrl();
        $token = $this->token($operationRun, $platform, $sha256);

        return "{$baseUrl}/api/update/artifacts/{$operationRun->id}/cli/{$platform}?token={$token}";
    }

    private function downloadBaseUrl(): string
    {
        $configuredBaseUrl = trim((string) config('orbit.updates.artifact_base_url', ''));

        if ($configuredBaseUrl !== '' && ! $this->isLocalOnlyBaseUrl($configuredBaseUrl)) {
            return rtrim($configuredBaseUrl, '/');
        }

        $gatewayBaseUrl = $this->gatewayBaseUrl();

        if ($gatewayBaseUrl !== null) {
            return $gatewayBaseUrl;
        }

        if ($configuredBaseUrl !== '') {
            return rtrim($configuredBaseUrl, '/');
        }

        return rtrim((string) config('app.url', 'http://localhost'), '/');
    }

    private function gatewayBaseUrl(): ?string
    {
        $gateway = $this->targets()->gatewayNode();

        if (! $gateway instanceof Node) {
            return null;
        }

        foreach ([$gateway->wireguard_address, $gateway->gateway_endpoint, $gateway->host] as $candidate) {
            $url = $this->gatewayUrlCandidate($candidate);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    private function gatewayUrlCandidate(?string $candidate): ?string
    {
        $candidate = trim((string) $candidate);

        if ($candidate === '') {
            return null;
        }

        if (str_contains($candidate, '://')) {
            return rtrim($candidate, '/');
        }

        if (filter_var($candidate, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $candidate = "[{$candidate}]";
        }

        return "https://{$candidate}";
    }

    private function isLocalOnlyBaseUrl(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            $host = $url;
        }

        $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));

        return $host === 'localhost'
        || $host === '0.0.0.0'
        || $host === '::1'
        || $host === 'host.docker.internal'
        || str_starts_with($host, '127.');
    }

    private function tokenMatches(OperationRun $operationRun, string $platform, string $sha256, ?string $token): bool
    {
        return is_string($token) && hash_equals($this->token($operationRun, $platform, $sha256), $token);
    }

    private function token(OperationRun $operationRun, string $platform, string $sha256): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                $operationRun->id,
                $platform,
                strtolower($sha256),
            ]),
            $this->tokenKey(),
        );
    }

    private function tokenKey(): string
    {
        $key = (string) config('app.key', '');

        return $key !== '' ? $key : 'orbit-update-artifacts';
    }

    private function artifactPath(OperationRun $operationRun, string $platform): string
    {
        $this->assertSafePlatform($platform);

        return $this->operationDirectory($operationRun)."/cli/{$platform}/orbit";
    }

    private function operationDirectory(OperationRun $operationRun): string
    {
        return $this->baseDirectory().'/'.$operationRun->id;
    }

    private function baseDirectory(): string
    {
        return rtrim((string) config('orbit.paths.config_root'), '/').'/update-artifacts';
    }

    private function assertSafePlatform(string $platform): void
    {
        if (preg_match('/^[a-z0-9._-]+$/', $platform) !== 1) {
            throw new RuntimeException("Unsupported CLI artifact platform [{$platform}].");
        }
    }

    private function assertHashMatches(string $path, string $expected): void
    {
        $actual = hash_file('sha256', $path);

        if ($actual !== strtolower($expected)) {
            throw new RuntimeException("CLI artifact hash mismatch for [{$path}].");
        }
    }

    private function cacheTtlSeconds(): int
    {
        return max(1, (int) config('orbit.updates.artifact_cache_ttl_seconds', 86400));
    }

    private function downloadTimeoutSeconds(): int
    {
        return max(1, (int) config('orbit.updates.artifact_download_timeout_seconds', 60));
    }

    private function targets(): FleetUpdateTargetSelector
    {
        return $this->targets ?? app(FleetUpdateTargetSelector::class);
    }
}
