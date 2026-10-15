<?php

declare(strict_types=1);

namespace App\Domain\SourceControl;

use InvalidArgumentException;

/** A full commit name: 40 lowercase hexadecimal characters for SHA-1, or 64 for SHA-256. */
final class GitCommitSha
{
    public static function validate(string $sha): string
    {
        if (! self::isValid($sha)) {
            throw new InvalidArgumentException('A commit SHA must be 40 or 64 lowercase hexadecimal characters.');
        }

        return $sha;
    }

    public static function isValid(string $sha): bool
    {
        return preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/', $sha) === 1;
    }
}
