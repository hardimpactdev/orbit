<?php

declare(strict_types=1);

namespace App\Data\Operations;

use App\Services\Gateway\GatewayImageReference;
use RuntimeException;

final readonly class ReleaseManifest
{
    private const int SupportedSchemaVersion = 1;

    public const string SourceGitHubRelease = 'github-release';

    public const string SourceTopologyCandidate = 'topology-candidate';

    private const array SupportedSources = [
        self::SourceGitHubRelease,
        self::SourceTopologyCandidate,
    ];

    /**
     * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
     * @param  array<string, array{url: string, sha256: string}>  $agentArtifacts
     * @param  array<string, string>  $roleImages
     * @param  array<string, mixed>  $snapshot
     */
    private function __construct(
        public int $schemaVersion,
        public string $version,
        public string $source,
        public ?string $buildId,
        public string $gatewayImage,
        public array $cliArtifacts,
        public array $agentArtifacts,
        public array $roleImages,
        private array $snapshot,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public static function fromArray(array $manifest): self
    {
        $snapshot = $manifest;
        $schemaVersion = self::schemaVersion($manifest['schema_version'] ?? 1);

        if (! array_key_exists('schema_version', $snapshot)) {
            $snapshot['schema_version'] = $schemaVersion;
        }

        if ($schemaVersion !== self::SupportedSchemaVersion) {
            throw new RuntimeException("Release manifest schema version [{$schemaVersion}] is not supported.");
        }

        $version = self::stringValue($manifest, 'version', 'version');
        $source = self::stringValue($manifest, 'source', 'source');
        $buildId = self::optionalStringValue($manifest, 'build_id', 'build id');

        if (! in_array($source, self::SupportedSources, true)) {
            throw new RuntimeException("Release manifest source [{$source}] is not supported.");
        }

        if ($source === self::SourceTopologyCandidate && $buildId === null) {
            throw new RuntimeException('Release manifest topology candidate build id must be a non-empty string.');
        }

        $images = self::arrayValue($manifest, 'images', 'images');
        $gatewayImage = self::stringValue($images, 'gateway', 'gateway image');
        self::assertDigestPinnedGatewayImage($gatewayImage);

        $cliArtifacts = self::cliArtifacts(self::arrayValue($manifest, 'cli_artifacts', 'CLI artifacts'));
        $agentArtifacts = array_key_exists('agent_artifacts', $manifest)
            ? self::artifacts(self::arrayValue($manifest, 'agent_artifacts', 'Agent artifacts'), 'Agent artifacts')
            : [];
        $roleImages = self::roleImages(self::arrayValue($manifest, 'role_images', 'role images'));

        return new self(
            schemaVersion: $schemaVersion,
            version: $version,
            source: $source,
            buildId: $buildId,
            gatewayImage: GatewayImageReference::fromString($gatewayImage)->canonical(),
            cliArtifacts: $cliArtifacts,
            agentArtifacts: $agentArtifacts,
            roleImages: $roleImages,
            snapshot: $snapshot,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return $this->snapshot;
    }

    private static function schemaVersion(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^[0-9]+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException('Release manifest schema version must be an integer.');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function stringValue(array $data, string $key, string $label): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Release manifest {$label} must be a non-empty string.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function optionalStringValue(array $data, string $key, string $label): ?string
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        $value = $data[$key];

        if (! is_string($value) || trim($value) === '') {
            throw new RuntimeException("Release manifest {$label} must be a non-empty string.");
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function arrayValue(array $data, string $key, string $label): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new RuntimeException("Release manifest {$label} must be an object.");
        }

        return $value;
    }

    private static function assertDigestPinnedGatewayImage(string $image): void
    {
        $reference = GatewayImageReference::fromString($image);

        if (! $reference->isDigestPinned()) {
            throw new RuntimeException('Release manifest gateway image must be digest-pinned.');
        }
    }

    /**
     * @param  array<string, mixed>  $artifacts
     * @return array<string, array{url: string, sha256: string}>
     */
    private static function cliArtifacts(array $artifacts): array
    {
        if ($artifacts === []) {
            throw new RuntimeException('Release manifest CLI artifacts cannot be empty.');
        }

        return self::artifacts($artifacts, 'CLI artifacts');
    }

    /**
     * @param  array<string, mixed>  $artifacts
     * @return array<string, array{url: string, sha256: string}>
     */
    private static function artifacts(array $artifacts, string $label): array
    {
        $validated = [];

        foreach ($artifacts as $platform => $artifact) {
            if (! is_string($platform) || trim($platform) === '' || ! is_array($artifact)) {
                throw new RuntimeException("Release manifest {$label} must be keyed by platform.");
            }

            $url = self::stringValue($artifact, 'url', "{$label} [{$platform}] URL");
            $sha256 = self::stringValue($artifact, 'sha256', "{$label} [{$platform}] sha256");

            if (preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new RuntimeException("Release manifest {$label} [{$platform}] sha256 must be a sha256 hash.");
            }

            $validated[trim($platform)] = [
                'url' => $url,
                'sha256' => $sha256,
            ];
        }

        ksort($validated);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $images
     * @return array<string, string>
     */
    private static function roleImages(array $images): array
    {
        if ($images === []) {
            throw new RuntimeException('Release manifest role images cannot be empty.');
        }

        $validated = [];

        foreach ($images as $role => $image) {
            if (! is_string($role) || trim($role) === '' || ! is_string($image) || trim($image) === '') {
                throw new RuntimeException(
                    'Release manifest role images must be keyed by role with non-empty image references.',
                );
            }

            $role = trim($role);
            $image = trim($image);

            $validated[$role] = $image;
        }

        ksort($validated);

        return $validated;
    }
}
