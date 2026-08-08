<?php

declare(strict_types=1);

namespace App\Services\Nodes;

/**
 * Whether a given uid/gid can read a path, by POSIX permission-class rules.
 *
 * The first matching class decides — owner, then group, then other — even when
 * a later class would be more permissive. A directory needs only the execute
 * bit to open a known file inside it; the read bit merely lists it.
 */
final class PosixReadAccess
{
    /**
     * @param  array{0: int, 1: int}  $subject  uid/gid requesting access
     * @param  array{0: int, 1: int}  $owner  uid/gid owning the path
     * @param  int  $mode  permission bits, already masked to 0o777
     */
    public static function permits(array $subject, array $owner, int $mode, bool $isDirectory): bool
    {
        $shift = match (true) {
            $subject[0] === $owner[0] => 6,
            $subject[1] === $owner[1] => 3,
            default => 0,
        };

        $required = $isDirectory ? 0o1 : 0o4;

        return (($mode >> $shift) & 0o7 & $required) === $required;
    }
}
