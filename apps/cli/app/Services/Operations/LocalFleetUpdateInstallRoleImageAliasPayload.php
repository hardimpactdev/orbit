<?php

declare(strict_types=1);

namespace App\Services\Operations;

final readonly class LocalFleetUpdateInstallRoleImageAliasPayload
{
    public function __construct(
        public string $source,
        public string $target,
    ) {}

    /**
     * @param  list<string>  $requiredImages
     * @return list<self>
     */
    public static function listFromPayload(mixed $payload, array $requiredImages): array
    {
        if ($payload === null) {
            return [];
        }

        if (! is_array($payload) || ! array_is_list($payload)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_image_aliases');
        }

        $aliases = array_map(self::fromPayload(...), $payload);

        foreach ($aliases as $alias) {
            if (! in_array($alias->source, $requiredImages, strict: true)) {
                throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_image_aliases.source');
            }
        }

        return $aliases;
    }

    private static function fromPayload(mixed $payload): self
    {
        if (! is_array($payload)) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_image_aliases');
        }

        $source = $payload['source'] ?? null;
        $target = $payload['target'] ?? null;

        if (
            ! is_string($source)
            || preg_match('/\A[^@\s]+(?:@sha256:[0-9a-f]{64})?\z/i', $source) !== 1
        ) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_image_aliases.source');
        }

        if (! is_string($target) || preg_match('/\A[^@\s]+\z/', $target) !== 1) {
            throw LocalFleetUpdateInstallCliPayloadField::validationFailure('role_image_aliases.target');
        }

        return new self(source: $source, target: $target);
    }
}
