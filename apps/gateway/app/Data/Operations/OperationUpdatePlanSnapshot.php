<?php

declare(strict_types=1);

namespace App\Data\Operations;

use App\Services\Gateway\GatewayImageReference;
use RuntimeException;

/**
 * @mago-expect lint:kan-defect
 * @mago-expect lint:mixed-assignment
 */
final readonly class OperationUpdatePlanSnapshot
{
    /**
     * @param  array<string, mixed>  $manifestSnapshot
     * @param  array<string, array{url: string, sha256: string}>  $cliArtifacts
     * @param  array<string, string>  $roleImages
     * @param  array<string, array{url: string, sha256: string}>  $agentArtifacts
     * @param  array<string, array{url: string, sha256: string, signature: string, version: string, platform: string, architecture: string}>  $desktopArtifacts
     */
    public function __construct(
        public string $targetVersion,
        public string $gatewayImage,
        public string $manifestSource,
        public string $manifestVersion,
        public array $manifestSnapshot,
        public array $cliArtifacts,
        public array $roleImages,
        public array $agentArtifacts = [],
        public array $desktopArtifacts = [],
    ) {
        $this->assertNonEmptyString($this->targetVersion, 'target version');
        $this->assertDigestPinnedGatewayImage($this->gatewayImage);
        $this->assertNonEmptyString($this->manifestSource, 'manifest source');
        $this->assertNonEmptyString($this->manifestVersion, 'manifest version');
        $this->assertSupportedManifestSource();

        if ($this->manifestSnapshot === []) {
            throw new RuntimeException('Update plan manifest snapshot cannot be empty.');
        }

        $this->assertTopologyCandidateManifestSnapshot();
        $this->assertRequiredArtifacts($this->cliArtifacts, 'CLI artifacts');
        $this->assertOptionalArtifacts($this->agentArtifacts, 'agent artifacts');
        $this->assertDesktopArtifacts($this->desktopArtifacts);
        $this->assertRoleImages($this->roleImages);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            targetVersion: self::stringValue($data, 'target_version'),
            gatewayImage: self::stringValue($data, 'gateway_image'),
            manifestSource: self::stringValue($data, 'manifest_source'),
            manifestVersion: self::stringValue($data, 'manifest_version'),
            manifestSnapshot: self::arrayValue($data, 'manifest_snapshot'),
            cliArtifacts: self::artifactMap(self::arrayValue($data, 'cli_artifacts'), 'CLI artifacts'),
            agentArtifacts: self::optionalArtifactMap($data, 'agent_artifacts', 'agent artifacts'),
            desktopArtifacts: self::optionalDesktopArtifactMap($data, 'desktop_artifacts'),
            roleImages: self::roleImageMap(self::arrayValue($data, 'role_images')),
        );
    }

    /**
     * @return array{
     *     target_version: string,
     *     gateway_image: string,
     *     manifest_source: string,
     *     manifest_version: string,
     *     manifest_snapshot: array<string, mixed>,
     *     cli_artifacts: array<string, array{url: string, sha256: string}>,
     *     agent_artifacts: array<string, array{url: string, sha256: string}>,
     *     desktop_artifacts: array<string, array{url: string, sha256: string, signature: string, version: string, platform: string, architecture: string}>,
     *     role_images: array<string, string>
     * }
     */
    public function toArray(): array
    {
        return [
            'target_version' => $this->targetVersion,
            'gateway_image' => $this->gatewayImage,
            'manifest_source' => $this->manifestSource,
            'manifest_version' => $this->manifestVersion,
            'manifest_snapshot' => $this->manifestSnapshot,
            'cli_artifacts' => $this->cliArtifacts,
            'agent_artifacts' => $this->agentArtifacts,
            'desktop_artifacts' => $this->desktopArtifacts,
            'role_images' => $this->roleImages,
        ];
    }

    private function assertNonEmptyString(string $value, string $label): void
    {
        if (trim($value) === '') {
            throw new RuntimeException("Update plan {$label} cannot be empty.");
        }
    }

    private function assertDigestPinnedGatewayImage(string $image): void
    {
        $reference = GatewayImageReference::fromString($image);

        if (! $reference->isDigestPinned()) {
            throw new RuntimeException('Update plan gateway image must be digest-pinned.');
        }
    }

    private function assertSupportedManifestSource(): void
    {
        if (! in_array(
            $this->manifestSource,
            [
                ReleaseManifest::SourceGitHubRelease,
                ReleaseManifest::SourceTopologyCandidate,
            ],
            true,
        )) {
            throw new RuntimeException("Update plan manifest source [{$this->manifestSource}] is not supported.");
        }
    }

    private function assertTopologyCandidateManifestSnapshot(): void
    {
        if ($this->manifestSource !== ReleaseManifest::SourceTopologyCandidate) {
            return;
        }

        $source = $this->manifestSnapshot['source'] ?? null;
        $buildId = $this->manifestSnapshot['build_id'] ?? null;

        if ($source !== ReleaseManifest::SourceTopologyCandidate || ! is_string($buildId) || trim($buildId) === '') {
            throw new RuntimeException(
                'Update plan topology candidate manifest snapshot must include a topology candidate source and build id.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $artifacts
     */
    private function assertRequiredArtifacts(array $artifacts, string $label): void
    {
        if ($artifacts === []) {
            throw new RuntimeException("Update plan {$label} cannot be empty.");
        }

        $this->assertArtifacts($artifacts, $label);
    }

    /**
     * @param  array<string, mixed>  $artifacts
     */
    private function assertOptionalArtifacts(array $artifacts, string $label): void
    {
        $this->assertArtifacts($artifacts, $label);
    }

    /**
     * @param  array<string, mixed>  $artifacts
     */
    private function assertDesktopArtifacts(array $artifacts): void
    {
        self::desktopArtifactMap($artifacts);
    }

    /**
     * @param  array<string, mixed>  $artifacts
     */
    private function assertArtifacts(array $artifacts, string $label): void
    {
        self::artifactMap($artifacts, $label);
    }

    /**
     * @param  array<string, mixed>  $images
     */
    private function assertRoleImages(array $images): void
    {
        self::roleImageMap($images);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function stringValue(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new RuntimeException("Update plan field [{$key}] must be a string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function arrayValue(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if (! is_array($value)) {
            throw new RuntimeException("Update plan field [{$key}] must be an array.");
        }

        return self::stringKeyedArray($value, "Update plan field [{$key}]");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{url: string, sha256: string}>
     */
    public static function optionalArtifactMap(array $data, string $key, string $label): array
    {
        if (! array_key_exists($key, $data)) {
            return [];
        }

        return self::artifactMap(self::arrayValue($data, $key), $label);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, array{url: string, sha256: string, signature: string, version: string, platform: string, architecture: string}>
     */
    public static function optionalDesktopArtifactMap(array $data, string $key): array
    {
        if (! array_key_exists($key, $data)) {
            return [];
        }

        return self::desktopArtifactMap(self::arrayValue($data, $key));
    }

    /**
     * @param  array<string, mixed>  $artifacts
     * @return array<string, array{url: string, sha256: string, signature: string, version: string, platform: string, architecture: string}>
     */
    public static function desktopArtifactMap(array $artifacts): array
    {
        $validated = [];

        foreach ($artifacts as $platform => $artifact) {
            if (trim($platform) === '' || ! is_array($artifact)) {
                throw new RuntimeException('Update plan desktop artifacts must be keyed by platform.');
            }

            $url = $artifact['url'] ?? null;
            $sha256 = $artifact['sha256'] ?? null;
            $signature = $artifact['signature'] ?? null;
            $version = $artifact['version'] ?? null;
            $artifactPlatform = $artifact['platform'] ?? null;
            $architecture = $artifact['architecture'] ?? null;

            if (! is_string($url) || trim($url) === '') {
                throw new RuntimeException("Update plan desktop artifacts [{$platform}] must include a URL.");
            }

            if (! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new RuntimeException("Update plan desktop artifacts [{$platform}] must include a sha256 hash.");
            }

            if (! is_string($signature) || trim($signature) === '') {
                throw new RuntimeException("Update plan desktop artifacts [{$platform}] must include a signature.");
            }

            if (! is_string($version) || trim($version) === '') {
                throw new RuntimeException("Update plan desktop artifacts [{$platform}] must include a version.");
            }

            if (! is_string($artifactPlatform) || trim($artifactPlatform) === '') {
                throw new RuntimeException("Update plan desktop artifacts [{$platform}] must include a platform.");
            }

            if (! is_string($architecture) || trim($architecture) === '') {
                throw new RuntimeException("Update plan desktop artifacts [{$platform}] must include an architecture.");
            }

            $validated[$platform] = [
                'url' => $url,
                'sha256' => strtolower($sha256),
                'signature' => $signature,
                'version' => $version,
                'platform' => $artifactPlatform,
                'architecture' => $architecture,
            ];
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $artifacts
     * @return array<string, array{url: string, sha256: string}>
     */
    public static function artifactMap(array $artifacts, string $label): array
    {
        $validated = [];

        foreach ($artifacts as $platform => $artifact) {
            if (trim($platform) === '' || ! is_array($artifact)) {
                throw new RuntimeException("Update plan {$label} must be keyed by platform.");
            }

            $url = $artifact['url'] ?? null;
            $sha256 = $artifact['sha256'] ?? null;

            if (! is_string($url) || trim($url) === '') {
                throw new RuntimeException("Update plan {$label} [{$platform}] must include a URL.");
            }

            if (! is_string($sha256) || preg_match('/^[a-f0-9]{64}$/', $sha256) !== 1) {
                throw new RuntimeException("Update plan {$label} [{$platform}] must include a sha256 hash.");
            }

            $validated[$platform] = [
                'url' => $url,
                'sha256' => strtolower($sha256),
            ];
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $images
     * @return array<string, string>
     */
    public static function roleImageMap(array $images): array
    {
        if ($images === []) {
            throw new RuntimeException('Update plan role images cannot be empty.');
        }

        $validated = [];

        foreach ($images as $role => $image) {
            if (trim($role) === '' || ! is_string($image) || trim($image) === '') {
                throw new RuntimeException(
                    'Update plan role images must be keyed by role with non-empty image references.',
                );
            }

            $validated[$role] = $image;
        }

        return $validated;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $value, string $label): array
    {
        $stringKeyed = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new RuntimeException("{$label} must be keyed by strings.");
            }

            $stringKeyed[$key] = $item;
        }

        return $stringKeyed;
    }
}
