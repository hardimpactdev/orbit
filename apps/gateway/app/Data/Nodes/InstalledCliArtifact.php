<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class InstalledCliArtifact implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $version,
        public string $platform,
        public string $sha256,
        public string $source,
        public ?string $buildId,
        public ?string $artifactUrl,
        public ?string $installedPath,
        public string $operationRunId,
        public Carbon $installedAt,
        public Carbon $verifiedAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            version: self::string($data['version'] ?? null),
            platform: self::string($data['platform'] ?? null),
            sha256: strtolower(self::string($data['sha256'] ?? null)),
            source: self::string($data['source'] ?? null),
            buildId: self::nullableString($data['build_id'] ?? null),
            artifactUrl: self::nullableString($data['artifact_url'] ?? null),
            installedPath: self::nullableString($data['installed_path'] ?? null),
            operationRunId: self::string($data['operation_run_id'] ?? null),
            installedAt: self::carbon($data['installed_at'] ?? null),
            verifiedAt: self::carbon($data['verified_at'] ?? null),
        );
    }

    public static function record(
        string $version,
        string $platform,
        string $sha256,
        string $source,
        ?string $buildId,
        ?string $artifactUrl,
        ?string $installedPath,
        string $operationRunId,
    ): self {
        $now = now();

        return new self(
            version: ltrim($version, 'v'),
            platform: $platform,
            sha256: strtolower($sha256),
            source: $source,
            buildId: $buildId,
            artifactUrl: $artifactUrl,
            installedPath: $installedPath,
            operationRunId: $operationRunId,
            installedAt: $now,
            verifiedAt: $now,
        );
    }

    public function matches(string $version, string $platform, string $sha256): bool
    {
        return (
            ltrim($this->version, 'v') === ltrim($version, 'v')
            && $this->platform === $platform
            && hash_equals($this->sha256, strtolower($sha256))
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'platform' => $this->platform,
            'sha256' => $this->sha256,
            'source' => $this->source,
            'build_id' => $this->buildId,
            'artifact_url' => $this->artifactUrl,
            'installed_path' => $this->installedPath,
            'operation_run_id' => $this->operationRunId,
            'installed_at' => $this->installedAt->toJSON(),
            'verified_at' => $this->verifiedAt->toJSON(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function carbon(mixed $value): Carbon
    {
        return is_string($value) && $value !== ''
            ? Carbon::parse($value)
            : now();
    }
}
