<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 *
 * @mago-expect lint:cyclomatic-complexity
 */
final readonly class InstalledAgentArtifact implements Arrayable, JsonSerializable
{
    public string $version;

    public string $platform;

    public string $sha256;

    public string $source;

    public ?string $buildId;

    public ?string $artifactUrl;

    public ?string $installedPath;

    public string $operationRunId;

    public Carbon $installedAt;

    public Carbon $verifiedAt;

    /**
     * @param  array{
     *     version: string,
     *     platform: string,
     *     sha256: string,
     *     source: string,
     *     build_id: string|null,
     *     artifact_url: string|null,
     *     installed_path: string|null,
     *     operation_run_id: string,
     *     installed_at: Carbon,
     *     verified_at: Carbon,
     * }  $attributes
     */
    private function __construct(array $attributes)
    {
        $this->version = $attributes['version'];
        $this->platform = $attributes['platform'];
        $this->sha256 = $attributes['sha256'];
        $this->source = $attributes['source'];
        $this->buildId = $attributes['build_id'];
        $this->artifactUrl = $attributes['artifact_url'];
        $this->installedPath = $attributes['installed_path'];
        $this->operationRunId = $attributes['operation_run_id'];
        $this->installedAt = $attributes['installed_at'];
        $this->verifiedAt = $attributes['verified_at'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self([
            'version' => self::string($data['version'] ?? null),
            'platform' => self::string($data['platform'] ?? null),
            'sha256' => strtolower(self::string($data['sha256'] ?? null)),
            'source' => self::string($data['source'] ?? null),
            'build_id' => self::nullableString($data['build_id'] ?? null),
            'artifact_url' => self::nullableString($data['artifact_url'] ?? null),
            'installed_path' => self::nullableString($data['installed_path'] ?? null),
            'operation_run_id' => self::string($data['operation_run_id'] ?? null),
            'installed_at' => self::carbon($data['installed_at'] ?? null),
            'verified_at' => self::carbon($data['verified_at'] ?? null),
        ]);
    }

    /**
     * @param  array{
     *     version: string,
     *     platform: string,
     *     sha256: string,
     *     source: string,
     *     build_id: string|null,
     *     artifact_url: string|null,
     *     installed_path: string|null,
     *     operation_run_id: string,
     * }  $artifact
     */
    public static function record(array $artifact): self
    {
        $now = Carbon::now();
        $sha256 = strtolower($artifact['sha256']);

        return new self([
            'version' => ltrim($artifact['version'], characters: 'v'),
            'platform' => $artifact['platform'],
            'sha256' => $sha256,
            'source' => $artifact['source'],
            'build_id' => $artifact['build_id'],
            'artifact_url' => $artifact['artifact_url'],
            'installed_path' => $artifact['installed_path'],
            'operation_run_id' => $artifact['operation_run_id'],
            'installed_at' => $now,
            'verified_at' => $now,
        ]);
    }

    public function matches(string $version, string $platform, string $sha256): bool
    {
        return (
            ltrim($this->version, characters: 'v') === ltrim($version, characters: 'v')
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
            ? new Carbon($value)
            : Carbon::now();
    }
}
