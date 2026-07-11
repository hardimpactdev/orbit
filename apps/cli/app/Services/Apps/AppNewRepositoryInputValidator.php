<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Orbit\Core\SourceControl\GitCloneReference;
use Orbit\Core\SourceControl\GitRepositoryCredentials;

final readonly class AppNewRepositoryInputValidator
{
    public function __construct(
        private LocalGitRepositoryReference $repositories = new LocalGitRepositoryReference,
    ) {}

    public function clone(string $repository): ?string
    {
        if (GitRepositoryCredentials::areEmbedded($repository)) {
            return 'Repository URLs must not contain embedded credentials.';
        }

        return GitCloneReference::isValid($repository)
            ? null
            : 'Repository must be a full Git URL or GitHub owner/repo shorthand.';
    }

    public function github(string $repository): ?string
    {
        return $this->repositories->isGithubSlug($repository)
            ? null
            : 'Repository must use GitHub owner/repo syntax.';
    }

    public function cloneOrFail(string $repository): void
    {
        $validation = $this->clone($repository);

        if ($validation !== null) {
            throw new AppNewSourceValidationFailed('repo', $validation);
        }
    }

    public function githubOrFail(string $field, string $repository): void
    {
        $validation = $this->github($repository);

        if ($validation !== null) {
            throw new AppNewSourceValidationFailed($field, $validation);
        }
    }
}
