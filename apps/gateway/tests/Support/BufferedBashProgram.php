<?php

declare(strict_types=1);

namespace Tests\Support;

/** Buffers finite fixture programs, not tests whose proof is stdin flow control. */
final class BufferedBashProgram
{
    public static function stdinPrelude(string $directory): string
    {
        // Keep cat and source on one line: cat consumes the remaining stdin, not another shell line.
        return 'fixture_program=$(mktemp '.escapeshellarg($directory.'/.orbit-program.XXXXXXXX').'); '
            .'trap \'rm -f -- "$fixture_program"\' EXIT; '
            .'cat > "$fixture_program"; (source "$fixture_program" "$@")'."\n";
    }
}
