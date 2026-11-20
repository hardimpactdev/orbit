<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Processes;

final readonly class ProcessArtifactData
{
    /** @param list<string>|null $versionArgs */
    public function __construct(
        public string $source,
        public string $repository,
        public string $asset,
        public string $binary,
        public ?string $checksums,
        public ?array $versionArgs,
        public ?string $version,
        public ?string $previousVersion,
        public ?string $sha256,
        public ?string $latestVersion,
        public ?string $latestCheckedAt,
    ) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(array $data): self
    {
        $string = static fn (string $key): ?string => is_string($data[$key] ?? null) ? $data[$key] : null;
        $args = $data['version_args'] ?? null;

        return new self(
            source: $string('source') ?? '',
            repository: $string('repository') ?? '',
            asset: $string('asset') ?? '',
            binary: $string('binary') ?? '',
            checksums: $string('checksums'),
            versionArgs: is_array($args) ? array_values(array_filter($args, is_string(...))) : null,
            version: $string('version'),
            previousVersion: $string('previous_version'),
            sha256: $string('sha256'),
            latestVersion: $string('latest_version'),
            latestCheckedAt: $string('latest_checked_at'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'repository' => $this->repository,
            'asset' => $this->asset,
            'binary' => $this->binary,
            'checksums' => $this->checksums,
            'version_args' => $this->versionArgs,
            'version' => $this->version,
            'previous_version' => $this->previousVersion,
            'sha256' => $this->sha256,
            'latest_version' => $this->latestVersion,
            'latest_checked_at' => $this->latestCheckedAt,
        ];
    }
}
