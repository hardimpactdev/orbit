<?php

declare(strict_types=1);

namespace App\Services\Operations;

final class LocalFleetUpdateInstallCliPayload
{
    public string $artifactUrl;

    public string $sha256;

    public string $installRoot;

    public string $binPath;

    public ?string $sharedBinaryPath;

    public ?LocalFleetUpdateInstallAgentPayload $agentArtifact;

    public ?LocalFleetUpdateInstallAgentServicePayload $agentService;

    /** @var list<LocalFleetUpdateInstallRoleImageArtifactPayload> */
    public array $roleImageArtifacts;

    /** @var list<LocalFleetUpdateInstallRoleImageAliasPayload> */
    public array $roleImageAliases;

    /**
     * @param  list<string>  $roleImages
     */
    private function __construct(
        public array $roleImages,
    ) {
        $this->artifactUrl = '';
        $this->sha256 = '';
        $this->installRoot = '';
        $this->binPath = '';
        $this->sharedBinaryPath = null;
        $this->agentArtifact = null;
        $this->agentService = null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $typedPayload = new self(self::roleImages($payload['role_images'] ?? null));
        $typedPayload->artifactUrl = LocalFleetUpdateInstallCliPayloadField::url(
            $payload['artifact_url'] ?? null,
            'artifact_url',
        );
        $typedPayload->sha256 = LocalFleetUpdateInstallCliPayloadField::sha256($payload['sha256'] ?? null);
        $typedPayload->installRoot = LocalFleetUpdateInstallCliPayloadField::absolutePath(
            $payload['install_root'] ?? null,
            'install_root',
        );
        $typedPayload->binPath = LocalFleetUpdateInstallCliPayloadField::absolutePath(
            $payload['bin_path'] ?? null,
            'bin_path',
        );
        $typedPayload->sharedBinaryPath = LocalFleetUpdateInstallCliPayloadField::optionalAbsolutePath(
            $payload['shared_binary_path'] ?? null,
            'shared_binary_path',
        );
        $typedPayload->agentArtifact = LocalFleetUpdateInstallAgentPayload::fromPayload(
            $payload['agent_artifact'] ?? null,
        );
        $typedPayload->agentService = LocalFleetUpdateInstallAgentServicePayload::fromPayload(
            $payload['agent_service'] ?? null,
        );
        $typedPayload->roleImageArtifacts = LocalFleetUpdateInstallRoleImageArtifactPayload::listFromPayload(
            $payload['role_image_artifacts'] ?? null,
        );
        $typedPayload->roleImageAliases = LocalFleetUpdateInstallRoleImageAliasPayload::listFromPayload(
            $payload['role_image_aliases'] ?? null,
            $typedPayload->roleImages,
        );

        return $typedPayload;
    }

    /**
     * @return list<string>
     */
    private static function roleImages(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_images');
        }

        $images = array_map(static fn (mixed $image): string => is_string($image) ? trim($image) : '', $value);

        if (in_array('', $images, strict: true)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_images');
        }

        return array_values(array_unique($images));
    }
}
