<?php

declare(strict_types=1);

namespace App\Services\Operations;

/** @mago-expect lint:excessive-parameter-list */
final readonly class LocalFleetUpdateInstallDesktopPayload
{
    public function __construct(
        public string $artifactUrl,
        public string $sha256,
        public string $signature,
        public string $version,
        public string $platform,
        public string $architecture,
        public string $stagedPath,
    ) {}

    public static function fromPayload(mixed $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('desktop_artifact');
        }

        return new self(
            artifactUrl: LocalFleetUpdateInstallCliPayloadField::url(
                $payload['artifact_url'] ?? null,
                'desktop_artifact.artifact_url',
            ),
            sha256: LocalFleetUpdateInstallCliPayloadField::sha256($payload['sha256'] ?? null),
            signature: LocalFleetUpdateInstallCliPayloadField::nonEmptyString(
                $payload['signature'] ?? null,
                'desktop_artifact.signature',
            ),
            version: LocalFleetUpdateInstallCliPayloadField::nonEmptyString(
                $payload['version'] ?? null,
                'desktop_artifact.version',
            ),
            platform: LocalFleetUpdateInstallCliPayloadField::nonEmptyString(
                $payload['platform'] ?? null,
                'desktop_artifact.platform',
            ),
            architecture: LocalFleetUpdateInstallCliPayloadField::nonEmptyString(
                $payload['architecture'] ?? null,
                'desktop_artifact.architecture',
            ),
            stagedPath: LocalFleetUpdateInstallCliPayloadField::absolutePath(
                $payload['staged_path'] ?? null,
                'desktop_artifact.staged_path',
            ),
        );
    }
}
