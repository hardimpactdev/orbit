<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class LocalGitRepositoryReference
{
    public function githubSlug(string $repository): ?string
    {
        if (preg_match('/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/', $repository) === 1) {
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
