<?php

declare(strict_types=1);

namespace Tests\Support;

use LogicException;

/** Inspect the same stdin payload the Python entry point decodes. */
final readonly class RecipeProgramPayload
{
    /** @return array<string, mixed> */
    public static function read(mixed $input): array
    {
        if (! is_string($input) || preg_match("~base64\.b64decode\('([A-Za-z0-9+/=]+)'\)~", $input, $match) !== 1) {
            throw new LogicException('The recipe program has no stdin payload.');
        }
        $bytes = base64_decode($match[1], true);
        $payload = json_decode($bytes === false ? '' : $bytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new LogicException('The recipe payload is not an object.');
        }

        return $payload;
    }
}
