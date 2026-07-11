<?php

declare(strict_types=1);

namespace App\Services\Apps;

use JsonException;

final readonly class LocalAppSourceTemplateRepositoryVerifier
{
    public function __construct(
        private LocalGitRepositoryReference $repositories = new LocalGitRepositoryReference,
    ) {}

    public function verify(string $templateRepository, string $newRepository, string $json): void
    {
        $existingRepository = $this->existingRepository($json);

        if (
            $existingRepository === null
            || ! $this->repositories->sameGithubRepository(
                $templateRepository,
                $existingRepository['template'],
            )
            || $existingRepository['visibility'] !== 'PRIVATE'
        ) {
            throw new LocalAppSourceCreateFailure(
                errorCode: 'app_source_create_failed',
                message: "Repository '{$newRepository}' already exists but is not a private repository created from '{$templateRepository}'.",
                meta: ['repository' => $newRepository],
            );
        }
    }

    /**
     * @return array{template: string, visibility: string}|null
     */
    private function existingRepository(string $json): ?array
    {
        try {
            /** @var array<string, mixed>|null $repository */
            $repository = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (
            ! is_array($repository)
            || ! is_array($repository['templateRepository'] ?? null)
            || ! is_string($repository['templateRepository']['nameWithOwner'] ?? null)
            || ! is_string($repository['visibility'] ?? null)
        ) {
            return null;
        }

        return [
            'template' => $repository['templateRepository']['nameWithOwner'],
            'visibility' => $repository['visibility'],
        ];
    }
}
