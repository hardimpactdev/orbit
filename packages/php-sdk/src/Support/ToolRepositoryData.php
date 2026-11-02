<?php

declare(strict_types=1);

namespace Orbit\Sdk\Support;

use InvalidArgumentException;

/** Typed public repository data shared by Tool and inventory responses. */
final class ToolRepositoryData
{
    /** @return array<string, mixed>|null */
    public static function fromGateway(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || array_is_list($value)) {
            throw new InvalidArgumentException('Invalid Tool repository response.');
        }
        $repository = [];
        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                throw new InvalidArgumentException('Invalid Tool repository response.');
            }
            $repository[$key] = $item;
        }

        return new CredentialRedactor()->redactArray($repository);
    }
}
