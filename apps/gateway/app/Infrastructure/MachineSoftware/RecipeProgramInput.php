<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

/** Recipe documents travel with Python source on stdin, outside native argv size limits. */
final readonly class RecipeProgramInput
{
    /** @param array<string, mixed> $payload */
    public static function source(string $program, array $payload, string $entryPoint): string
    {
        $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));

        return $program."\nif __name__ == '__main__':\n    import base64\n    sys.argv.append(base64.b64decode('".$encoded."').decode())\n    sys.exit(".$entryPoint."())\n";
    }
}
