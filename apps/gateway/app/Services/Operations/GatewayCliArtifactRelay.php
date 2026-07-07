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
    private const string ARTIFACT_KIND_CLI = 'cli';

    private const string ARTIFACT_KIND_AGENT = 'agent';

    public function __construct(
        private readonly ?FleetUpdateTargetSelector $targets = null,
    ) {}

    /**
     * @return array{url: string, sha256: string, source_url: string}
     */
    public function artifactFor(OperationRun $operationRun, OperationUpdatePlan $plan, string $platform): array
    {
        return $this->artifactForKind($operationRun, $plan, self::ARTIFACT_KIND_CLI, $platform);
    }

    /**
     * @return array{url: string, sha256: string, source_url: string}|null
     */
    public function agentArtifactFor(OperationRun $operationRun, OperationUpdatePlan $plan, string $platform): ?array
    {
        if (! $this->planHasArtifact($plan, self::ARTIFACT_KIND_AGENT, $platform)) {
            return null;
        }

        return $this->artifactForKind($operationRun, $plan, self::ARTIFACT_KIND_AGENT, $platform);
    }

    /**
     * @return array{url: string, sha256: string, source_url: string}
     */
    private function artifactForKind(
        OperationRun $operationRun,
        OperationUpdatePlan $plan,
        string $artifactKind,
        string $platform,
    ): array {
        $artifact = $this->manifestArtifact($plan, $artifactKind, $platform);

        if (! $this->shouldRelayArtifact($artifact)) {
            return [
                'url' => $artifact['url'],
                'sha256' => $artifact['sha256'],
                'source_url' => $artifact['url'],
            ];
        }

        $this->stageArtifact($operationRun, $platform, $artifact, $artifactKind);

        return [
            'url' => $this->downloadUrl($operationRun, $artifactKind, $platform, $artifact['sha256']),
            'sha256' => $artifact['sha256'],
            'source_url' => $artifact['url'],
        ];
    }

    public function stage(OperationRun $operationRun, OperationUpdatePlan $plan): void
    {
        $artifacts = [];

        $artifacts = [
            ...$this->artifactsToRelay($plan, self::ARTIFACT_KIND_CLI),
            ...$this->artifactsToRelay($plan, self::ARTIFACT_KIND_AGENT),
        ];

        if ($artifacts === []) {
            return;
        }

        $this->cleanupExpired();

        foreach ($artifacts as $artifactKey => $artifact) {
            [$artifactKind, $platform] = explode(separator: ':', string: $artifactKey, limit: 2);

            $this->stageArtifact($operationRun, $platform, $artifact, $artifactKind);
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

    public function downloadPath(
        OperationRun $operationRun,
        string $artifactKind,
        string $platform,
        ?string $token,
    ): string {
        $plan = $operationRun->updatePlan()->first();

        if (! $plan instanceof OperationUpdatePlan) {
            throw new RuntimeException('The update plan for this operation was not found.');
        }

        $artifact = $this->manifestArtifact($plan, $artifactKind, $platform);

        if (! $this->tokenMatches($operationRun, $artifactKind, $platform, $artifact['sha256'], $token)) {
            throw new RuntimeException('The update artifact token is invalid.');
        }

        $path = $this->artifactPath($operationRun, $artifactKind, $platform);

        if (! File::isFile($path)) {
            throw new RuntimeException('The update artifact has not been staged on this gateway.');
        }

        $this->assertHashMatches($path, $artifact['sha256']);

        return $path;
    }

    /**
     * @param  array{url: string, sha256: string}  $artifact
     */
    private function stageArtifact(
        OperationRun $operationRun,
        string $platform,
        array $artifact,
        string $artifactKind = self::ARTIFACT_KIND_CLI,
    ): void {
        $path = $this->artifactPath($operationRun, $artifactKind, $platform);

        if (File::isFile($path) && hash_file('sha256', $path) === strtolower($artifact['sha256'])) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));

        $temporaryPath = $path.'.tmp-'.Str::random(8);

        try {
            $response = Http::timeout($this->downloadTimeoutSeconds())->get($artifact['url']);

            if (! $response->successful()) {
                throw new RuntimeException("Could not download {$artifactKind} artifact [{$artifact['url']}].");
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
    private function manifestArtifact(OperationUpdatePlan $plan, string $artifactKind, string $platform): array
    {
        $this->assertSafeArtifactKind($artifactKind);
        $this->assertSafePlatform($platform);

        $artifact = match ($artifactKind) {
            self::ARTIFACT_KIND_CLI => $plan->cli_artifacts[$platform] ?? null,
            self::ARTIFACT_KIND_AGENT => (($plan->agent_artifacts ?? []))[$platform] ?? null,
            default => null,
        };

        if (
            ! is_array($artifact)
            || ! is_string($artifact['url'] ?? null)
            || ! is_string($artifact['sha256'] ?? null)
        ) {
            throw new RuntimeException(
                "Update plan does not contain a {$artifactKind} artifact for platform [{$platform}].",
            );
        }

        return [
            'url' => $artifact['url'],
            'sha256' => strtolower($artifact['sha256']),
        ];
    }

    private function downloadUrl(
        OperationRun $operationRun,
        string $artifactKind,
        string $platform,
        string $sha256,
    ): string {
        $baseUrl = $this->downloadBaseUrl();
        $token = $this->token($operationRun, $artifactKind, $platform, $sha256);

        return "{$baseUrl}/api/update/artifacts/{$operationRun->id}/{$artifactKind}/{$platform}?token={$token}";
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

    /**
     * @param  array{url: string, sha256: string}  $artifact
     */
    private function shouldRelayArtifact(array $artifact): bool
    {
        $scheme = strtolower((string) parse_url($artifact['url'], PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], strict: true)) {
            return true;
        }

        return $this->isLocalOnlyBaseUrl($artifact['url']);
    }

    private function tokenMatches(
        OperationRun $operationRun,
        string $artifactKind,
        string $platform,
        string $sha256,
        ?string $token,
    ): bool {
        return is_string($token) && hash_equals($this->token($operationRun, $artifactKind, $platform, $sha256), $token);
    }

    private function token(OperationRun $operationRun, string $artifactKind, string $platform, string $sha256): string
    {
        return hash_hmac(
            'sha256',
            implode('|', [
                $operationRun->id,
                $artifactKind,
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

    private function artifactPath(OperationRun $operationRun, string $artifactKind, string $platform): string
    {
        $this->assertSafeArtifactKind($artifactKind);
        $this->assertSafePlatform($platform);

        $file = $artifactKind === self::ARTIFACT_KIND_AGENT ? 'orbit-agent' : 'orbit';

        return $this->operationDirectory($operationRun)."/{$artifactKind}/{$platform}/{$file}";
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
            throw new RuntimeException("Unsupported update artifact platform [{$platform}].");
        }
    }

    private function assertSafeArtifactKind(string $artifactKind): void
    {
        if (! in_array($artifactKind, [self::ARTIFACT_KIND_CLI, self::ARTIFACT_KIND_AGENT], strict: true)) {
            throw new RuntimeException("Unsupported update artifact kind [{$artifactKind}].");
        }
    }

    private function assertHashMatches(string $path, string $expected): void
    {
        $actual = hash_file('sha256', $path);

        if ($actual !== strtolower($expected)) {
            throw new RuntimeException("Update artifact hash mismatch for [{$path}].");
        }
    }

    /**
     * @return array<string, array{url: string, sha256: string}>
     */
    private function artifactsToRelay(OperationUpdatePlan $plan, string $artifactKind): array
    {
        $manifestArtifacts = match ($artifactKind) {
            self::ARTIFACT_KIND_CLI => $plan->cli_artifacts,
            self::ARTIFACT_KIND_AGENT => $plan->agent_artifacts ?? [],
            default => [],
        };
        $artifacts = [];

        foreach (array_keys($manifestArtifacts) as $platform) {
            if (! is_string($platform)) {
                continue;
            }

            $artifact = $this->manifestArtifact($plan, $artifactKind, $platform);

            if (! $this->shouldRelayArtifact($artifact)) {
                continue;
            }

            $artifacts["{$artifactKind}:{$platform}"] = $artifact;
        }

        return $artifacts;
    }

    private function planHasArtifact(OperationUpdatePlan $plan, string $artifactKind, string $platform): bool
    {
        return match ($artifactKind) {
            self::ARTIFACT_KIND_CLI => is_array($plan->cli_artifacts[$platform] ?? null),
            self::ARTIFACT_KIND_AGENT => is_array((($plan->agent_artifacts ?? []))[$platform] ?? null),
            default => false,
        };
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
