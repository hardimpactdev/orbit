<?php

declare(strict_types=1);

namespace Tests\Support;

final class BashFixtureFileReader
{
    /** A byte-preserving cat shim for one private fixture file, not for program stdin. */
    public static function forFile(string $path): string
    {
        $file = escapeshellarg($path);

        return <<<BASH
            cat() {
                if [ "\$#" -ne 2 ] || [ "\$1" != -- ] || [ "\$2" != {$file} ]; then
                    command cat "\$@"
                    return
                fi
                local chunk
                {
                    while IFS= read -r -d '' chunk; do
                        printf '%s\\0' "\$chunk"
                    done
                    printf '%s' "\$chunk"
                } < "\$2"
            }

            BASH;
    }
}
