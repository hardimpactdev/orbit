<?php

declare(strict_types=1);

namespace App\Support;

final class GitRepositoryReference
{
    public static function canonicalize(?string $repository): string|false|null
    {
        if ($repository === null) {
            return null;
        }

        if (self::isGithubShorthand($repository)) {
            return "git@github.com:{$repository}.git";
        }

        if (preg_match('/^(git@|https:\/\/|ssh:\/\/).+/', $repository)) {
            return $repository;
        }

        return false;
    }

    public static function cloneCommand(string $repository, string $path): string
    {
        $githubSlug = self::githubSlug($repository);

        if ($githubSlug !== null) {
            return sprintf('gh repo clone %s %s', escapeshellarg($githubSlug), escapeshellarg($path));
        }

        return sprintf('git clone %s %s', escapeshellarg($repository), escapeshellarg($path));
    }

    public static function transport(string $repository): string
    {
        if (self::githubSlug($repository) !== null) {
            return 'github';
        }

        return str_starts_with($repository, 'https://') ? 'https' : 'ssh';
    }

    public static function githubSlug(string $repository): ?string
    {
        if (self::isGithubShorthand($repository)) {
            return $repository;
        }

        $patterns = [
            '/^git@github\.com:(?<owner>[a-zA-Z0-9._-]+)\/(?<repo>[a-zA-Z0-9._-]+)$/',
            '/^https:\/\/github\.com\/(?<owner>[a-zA-Z0-9._-]+)\/(?<repo>[a-zA-Z0-9._-]+)\/?$/',
            '/^ssh:\/\/git@github\.com\/(?<owner>[a-zA-Z0-9._-]+)\/(?<repo>[a-zA-Z0-9._-]+)\/?$/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $repository, $matches)) {
                $repositoryName = preg_replace('/\.git$/', '', $matches['repo']);

                if (! is_string($repositoryName)) {
                    return null;
                }

                return $matches['owner'].'/'.$repositoryName;
            }
        }

        return null;
    }

    private static function isGithubShorthand(string $repository): bool
    {
        return preg_match('/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/', $repository) === 1;
    }
}
