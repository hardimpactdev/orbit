<?php

declare(strict_types=1);

namespace App\Services\Release;

use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;

class CandidateReleaseChannelActivator
{
    private const string CandidateSource = 'topology-candidate';

    private const string ChannelManifest = 'orbit-release-manifest.json';

    private const string DefaultCandidateManifest = 'orbit-release-manifest.candidate.json';

    /**
     * @return array{
     *     channel: string,
     *     build_id: string,
     *     source_path: string,
     *     channel_path: string,
     *     manifest_url: string,
     *     version: string
     * }
     */
    public function activate(
        string $buildId,
        string $channel = 'live-test',
        string $manifest = self::DefaultCandidateManifest,
    ): array {
        $buildId = trim($buildId);
        $channel = trim($channel);
        $manifest = trim($manifest);

        $this->assertPathSafeSegment($buildId, 'Candidate build id');
        $this->assertPathSafeSegment($channel, 'Candidate channel');
        $this->assertCandidateManifestFilename($manifest);

        $baseUrl = $this->artifactBaseUrl();
        $sourcePath = "candidates/{$buildId}/{$manifest}";
        $channelPath = "channels/{$channel}/".self::ChannelManifest;

        $disk = Storage::disk((string) config('orbit.artifacts.disk', 'orbit-artifacts'));

        if (! $disk->exists($sourcePath)) {
            throw new RuntimeException("Candidate manifest [{$sourcePath}] was not found.");
        }

        $contents = $disk->get($sourcePath);

        if (! is_string($contents) || trim($contents) === '') {
            throw new RuntimeException("Candidate manifest [{$sourcePath}] is empty.");
        }

        $payload = $this->decodeManifest($contents, $sourcePath);
        $version = $this->validatedVersion($payload, $sourcePath);

        if (($payload['source'] ?? null) !== self::CandidateSource) {
            throw new RuntimeException("Candidate manifest [{$sourcePath}] must use source [".self::CandidateSource.'].');
        }

        $manifestBuildId = $payload['build_id'] ?? null;

        if ($manifestBuildId !== $buildId) {
            $actualBuildId = is_string($manifestBuildId) && $manifestBuildId !== ''
                ? $manifestBuildId
                : 'missing';

            throw new RuntimeException("Candidate manifest build id [{$actualBuildId}] does not match [{$buildId}].");
        }

        $disk->put($channelPath, $contents, 'public');

        return [
            'channel' => $channel,
            'build_id' => $buildId,
            'source_path' => $sourcePath,
            'channel_path' => $channelPath,
            'manifest_url' => "{$baseUrl}/{$channelPath}",
            'version' => $version,
        ];
    }

    private function assertPathSafeSegment(string $value, string $label): void
    {
        if (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/', $value) === 1) {
            return;
        }

        throw new RuntimeException("{$label} [{$value}] is not path safe.");
    }

    private function assertCandidateManifestFilename(string $manifest): void
    {
        $this->assertPathSafeSegment($manifest, 'Candidate manifest filename');

        if (str_ends_with($manifest, '.json')) {
            return;
        }

        throw new RuntimeException("Candidate manifest filename [{$manifest}] must end with [.json].");
    }

    private function artifactBaseUrl(): string
    {
        $baseUrl = rtrim((string) config('orbit.artifacts.base_url'), '/');

        if ($baseUrl !== '') {
            return $baseUrl;
        }

        throw new RuntimeException('ORBIT_ARTIFACTS_BASE_URL is required to activate a release candidate channel.');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeManifest(string $contents, string $sourcePath): array
    {
        try {
            $payload = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Candidate manifest [{$sourcePath}] is not valid JSON.", previous: $exception);
        }

        if (is_array($payload)) {
            /** @var array<string, mixed> $payload */
            return $payload;
        }

        throw new RuntimeException("Candidate manifest [{$sourcePath}] must contain a JSON object.");
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function validatedVersion(array $payload, string $sourcePath): string
    {
        $version = $payload['version'] ?? null;

        if (is_string($version) && trim($version) !== '') {
            return $version;
        }

        throw new RuntimeException("Candidate manifest [{$sourcePath}] is missing a version.");
    }
}
