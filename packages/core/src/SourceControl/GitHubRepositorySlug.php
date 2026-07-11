<?php

declare(strict_types=1);

namespace Orbit\Core\SourceControl;

final class GitHubRepositorySlug
{
    public static function isValid(string $repository): bool
    {
        return (
            preg_match(
                '/^[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,37}[a-zA-Z0-9])?\/[a-zA-Z0-9._-]{1,100}$/',
                $repository,
            ) === 1
        );
    }
}
