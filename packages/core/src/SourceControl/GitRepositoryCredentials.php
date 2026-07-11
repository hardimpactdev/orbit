<?php

declare(strict_types=1);

namespace Orbit\Core\SourceControl;

final class GitRepositoryCredentials
{
    public static function areEmbedded(string $repository): bool
    {
        $parts = parse_url($repository);

        if (! is_array($parts)) {
            return false;
        }

        return match ($parts['scheme'] ?? null) {
            'https' => array_key_exists('user', $parts) || array_key_exists('pass', $parts),
            'ssh' => array_key_exists('pass', $parts),
            default => false,
        };
    }
}
