<?php

declare(strict_types=1);

namespace App\Services\Operations;

final readonly class LocalFleetUpdateInstallRoleImageArtifactPayload
{
    public function __construct(
        public string $image,
        public string $artifactUrl,
        public string $sha256,
    ) {}

    /**
     * @return list<self>
     */
    public static function listFromPayload(mixed $payload): array
    {
        if ($payload === null) {
            return [];
        }

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_image_artifacts');
        }

        return array_map(self::fromPayload(...), $payload);
    }

    private static function fromPayload(mixed $payload): self
    {
        if (! is_array($payload)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_image_artifacts');
        }

        if (! isset($payload['image']) || ! is_string($payload['image'])) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_image_artifacts.image');
        }

        $image = $payload['image'];

        if (preg_match('/\A[^\s]+\z/', $image) !== 1) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_image_artifacts.image');
        }

        return new self(
            image: $image,
            artifactUrl: LocalFleetUpdateInstallCliPayloadField::url(
                $payload['url'] ?? null,
                'role_image_artifacts.url',
            ),
            sha256: LocalFleetUpdateInstallCliPayloadField::sha256(
                $payload['sha256'] ?? null,
                'role_image_artifacts.sha256',
            ),
        );
    }
}
