<?php

declare(strict_types=1);

namespace App\Services\Operations;

final readonly class LocalFleetUpdateInstallAgentPayload
{
    public function __construct(
        public string $artifactUrl,
        public string $sha256,
        public string $binPath,
    ) {}

    public static function fromPayload(mixed $payload): ?self
    {
        if ($payload === null) {
            return null;
        }

        if (! is_array($payload)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('agent_artifact');
        }

        return new self(
            artifactUrl: LocalFleetUpdateInstallCliPayloadField::url(
                $payload['artifact_url'] ?? null,
                'agent_artifact.artifact_url',
            ),
            sha256: LocalFleetUpdateInstallCliPayloadField::sha256($payload['sha256'] ?? null),
            binPath: LocalFleetUpdateInstallCliPayloadField::absolutePath(
                $payload['bin_path'] ?? null,
                'agent_artifact.bin_path',
            ),
        );
    }
}
