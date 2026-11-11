<?php

declare(strict_types=1);

namespace App\Domain\GitHub;

/** Request-local facts from the source read; never a shared credential cache. */
final class RepositoryReadContext
{
    /** @var array{GitHubRepository, GitHubAppCredentials, int}|null */
    public ?array $installation = null;

    public ?GitReadEnvironment $environment = null;

    /** @return array{type: class-string} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }
}
