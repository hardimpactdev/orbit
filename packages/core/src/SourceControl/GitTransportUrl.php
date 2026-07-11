<?php

declare(strict_types=1);

namespace Orbit\Core\SourceControl;

final class GitTransportUrl
{
    public static function isValid(string $repository): bool
    {
        $parts = parse_url($repository);

        if (! is_array($parts)) {
            return false;
        }

        $host = $parts['host'] ?? null;
        $scheme = $parts['scheme'] ?? null;
        $path = $parts['path'] ?? null;

        return (
            is_string($host)
            && in_array($scheme, ['https', 'ssh'], strict: true)
            && is_string($path)
            && ! array_key_exists('query', $parts)
            && ! array_key_exists('fragment', $parts)
            && ! GitRepositoryCredentials::areEmbedded($repository)
        );
    }
}
