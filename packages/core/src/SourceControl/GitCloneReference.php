<?php

declare(strict_types=1);

namespace Orbit\Core\SourceControl;

final class GitCloneReference
{
    public static function isValid(string $repository): bool
    {
        return (
            preg_match('//u', $repository) === 1
            && preg_match('/[\p{Z}\p{C}]/u', $repository) !== 1
            && (
                GitHubRepositorySlug::isValid($repository)
                || preg_match('/^git@[^:\s?#]+:[^\s?#]+$/', $repository) === 1
                || GitTransportUrl::isValid($repository)
            )
        );
    }
}
