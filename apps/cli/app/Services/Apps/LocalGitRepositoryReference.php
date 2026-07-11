<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Orbit\Core\SourceControl\GitHubRepositorySlug;

final readonly class LocalGitRepositoryReference
{
    public function isGithubSlug(string $repository): bool
    {
        return GitHubRepositorySlug::isValid($repository);
    }

    public function githubSlug(string $repository): ?string
    {
        if ($this->isGithubSlug($repository)) {
            return $repository;
        }

        $patterns = [
            '/^git@github\.com:(?<owner>[a-zA-Z0-9._-]+)\/(?<repo>[a-zA-Z0-9._-]+)$/',
            '/^https:\/\/github\.com\/(?<owner>[a-zA-Z0-9._-]+)\/(?<repo>[a-zA-Z0-9._-]+)\/?$/',
            '/^ssh:\/\/git@github\.com\/(?<owner>[a-zA-Z0-9._-]+)\/(?<repo>[a-zA-Z0-9._-]+)\/?$/',
        ];

        foreach ($patterns as $pattern) {
            $matches = [];

            if (preg_match($pattern, $repository, $matches) !== 1) {
                continue;
            }

            $repositoryName = preg_replace(pattern: '/\.git$/', replacement: '', subject: $matches['repo']);

            if (! is_string($repositoryName)) {
                return null;
            }

            return $matches['owner'].'/'.$repositoryName;
        }

        return null;
    }

    public function sameGithubRepository(string $first, string $second): bool
    {
        $firstSlug = $this->githubSlug($first);
        $secondSlug = $this->githubSlug($second);

        return $firstSlug !== null && $secondSlug !== null && strcasecmp($firstSlug, $secondSlug) === 0;
    }

    public function matchesOrigin(string $repository, string $origin): bool
    {
        if ($this->sameGithubRepository($repository, $origin)) {
            return true;
        }

        return in_array($origin, $this->expectedOrigins($repository), strict: true);
    }

    /**
     * @return list<string>
     */
    public function expectedOrigins(string $repository): array
    {
        $githubSlug = $this->githubSlug($repository);

        if ($githubSlug === null) {
            return [$repository];
        }

        return [
            "git@github.com:{$githubSlug}.git",
            "https://github.com/{$githubSlug}.git",
            "ssh://git@github.com/{$githubSlug}.git",
        ];
    }
}
