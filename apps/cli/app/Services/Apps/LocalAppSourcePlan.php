<?php

declare(strict_types=1);

namespace App\Services\Apps;

use Orbit\Core\SourceControl\GitCloneReference;

final readonly class LocalAppSourcePlan
{
    private function __construct(
        public string $repository,
        public ?string $templateRepository,
    ) {}

    public static function fromInput(
        mixed $repository,
        mixed $templateRepository,
        mixed $newRepository,
    ): self {
        $repository = self::repository($repository);
        $templateRepository = self::repository($templateRepository);
        $newRepository = self::repository($newRepository);
        if ($repository !== null) {
            if ($templateRepository !== null || $newRepository !== null) {
                self::throwInvalid();
            }

            if (! GitCloneReference::isValid($repository)) {
                self::throwInvalid('Repository must be a full Git URL or GitHub owner/repo shorthand.');
            }

            return new self($repository, null);
        }

        if ($templateRepository === null || $newRepository === null) {
            self::throwInvalid();
        }

        $repositories = new LocalGitRepositoryReference;

        if (! $repositories->isGithubSlug($templateRepository) || ! $repositories->isGithubSlug($newRepository)) {
            self::throwInvalid('Template and new repositories must use GitHub owner/repo syntax.');
        }

        return new self($newRepository, $templateRepository);
    }

    private static function repository(mixed $value): ?string
    {
        if ($value === null || $value === false || $value === '') {
            return null;
        }

        if (is_string($value) && ! str_contains($value, "\0")) {
            return $value;
        }

        throw new LocalAppSourceCreateFailure(
            errorCode: 'validation_failed',
            message: 'Repository is invalid.',
            meta: ['field' => 'repository'],
        );
    }

    private static function throwInvalid(
        string $message = 'Supply repository, or supply both template_repository and new_repository.',
    ): never {
        throw new LocalAppSourceCreateFailure(
            errorCode: 'validation_failed',
            message: $message,
            meta: [
                'field' => 'source',
                'fields' => ['repository', 'template_repository', 'new_repository'],
            ],
        );
    }
}
