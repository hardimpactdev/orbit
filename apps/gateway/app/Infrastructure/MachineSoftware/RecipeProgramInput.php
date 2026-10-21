<?php

declare(strict_types=1);

namespace App\Infrastructure\MachineSoftware;

use App\Infrastructure\RemotePrograms\RemoteProgramCatalog;

/** Recipe documents travel with Python source on stdin, outside native argv size limits. */
final readonly class RecipeProgramInput
{
    /** @param array<string, mixed> $payload */
    public static function source(string $program, array $payload, string $entryPoint): string
    {
        if (str_contains($program, '# ORBIT_VP_STORE_PROGRAM')) {
            $source = new RemoteProgramCatalog()->program('tools.install-vp-environment')->source;
            $program = str_replace("VP_STORE_PROGRAM = ''  # ORBIT_VP_STORE_PROGRAM", 'VP_STORE_PROGRAM = '.json_encode($source, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $program);
        }
        $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));

        return $program."\nif __name__ == '__main__':\n    import base64\n    sys.argv.append(base64.b64decode('".$encoded."').decode())\n    sys.exit(".$entryPoint."())\n";
    }
}
