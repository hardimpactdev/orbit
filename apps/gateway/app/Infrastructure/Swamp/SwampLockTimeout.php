<?php

declare(strict_types=1);

namespace App\Infrastructure\Swamp;

/**
 * Swamp's own error when a step waited too long for a behavior lock that another run held (docs/cli/README.md#behavior-names). The
 * whole failure detail is that one line, for a lock file under `.swamp/data`. It names the holder unless the holder let go at the
 * deadline. A step that ran out of its own time reads `<step> timed out after <n>s` and is no lock timeout.
 */
final class SwampLockTimeout
{
    public static function matches(mixed $detail): bool
    {
        return is_string($detail)
            && preg_match('~\ALock "[^"\n]*/\.swamp/data/[^"\n]+/\.lock"(?: held by [^\n]+ \(pid [1-9][0-9]*\))? — timed out after [0-9]+ms\z~u', trim($detail)) === 1;
    }
}
