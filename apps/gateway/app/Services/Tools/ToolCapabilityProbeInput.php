<?php

declare(strict_types=1);

namespace App\Services\Tools;

use App\Tools\UserScopedCliUsers;

/** @mago-expect lint:cyclomatic-complexity */
final readonly class ToolCapabilityProbeInput
{
    /** @mago-expect lint:excessive-parameter-list */
    public function __construct(
        public string $binary,
        public string $binaryAsUser = '',
        public string $versionCommand = '',
        public string $service = '',
        public string $providerCommand = '',
        public string $container = '',
        public string $extraProbe = '',
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fromMetadata(
        array $metadata,
        string $binaryFallback,
        ?string $containerOverride = null,
    ): self {
        $extraProbe = is_string($metadata['probe'] ?? null) ? $metadata['probe'] : '';

        return new self(
            binary: is_string($metadata['binary'] ?? null) ? $metadata['binary'] : $binaryFallback,
            binaryAsUser: UserScopedCliUsers::normalize($metadata['binary_as_user'] ?? null) ?? '',
            versionCommand: is_string($metadata['version_command'] ?? null) ? $metadata['version_command'] : '',
            service: is_string($metadata['service'] ?? null) ? $metadata['service'] : '',
            providerCommand: is_string($metadata['provider_command'] ?? null) ? $metadata['provider_command'] : '',
            container: $containerOverride ?? (is_string($metadata['container'] ?? null) ? $metadata['container'] : ''),
            extraProbe: $extraProbe !== 'docker_images' ? $extraProbe : '',
        );
    }
}
