<?php

declare(strict_types=1);

namespace App\Data\Nodes;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Carbon;
use JsonSerializable;

/**
 * @implements Arrayable<string, mixed>
 */
final readonly class InstalledGatewayImage implements Arrayable, JsonSerializable
{
    public function __construct(
        public string $version,
        public string $image,
        public ?string $digest,
        public string $source,
        public ?string $buildId,
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
            image: self::string($data['image'] ?? null),
            digest: self::nullableString($data['digest'] ?? null),
            source: self::string($data['source'] ?? null),
            buildId: self::nullableString($data['build_id'] ?? null),
            operationRunId: self::string($data['operation_run_id'] ?? null),
            installedAt: self::carbon($data['installed_at'] ?? null),
            verifiedAt: self::carbon($data['verified_at'] ?? null),
        );
    }

    public static function record(
        string $version,
        string $image,
        ?string $digest,
        string $source,
        ?string $buildId,
        string $operationRunId,
    ): self {
        $now = now();

        return new self(
            version: ltrim($version, 'v'),
            image: $image,
            digest: $digest,
            source: $source,
            buildId: $buildId,
            operationRunId: $operationRunId,
            installedAt: $now,
            verifiedAt: $now,
        );
    }

    public function matches(string $version, string $image, ?string $digest): bool
    {
        if ($digest !== null && $this->digest !== null) {
            return hash_equals($this->digest, $digest);
        }

        return ltrim($this->version, 'v') === ltrim($version, 'v') && $this->image === $image;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'image' => $this->image,
            'digest' => $this->digest,
            'source' => $this->source,
            'build_id' => $this->buildId,
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
