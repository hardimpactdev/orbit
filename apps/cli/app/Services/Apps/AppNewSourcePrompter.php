<?php

declare(strict_types=1);

namespace App\Services\Apps;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

final readonly class AppNewSourcePrompter
{
    public function __construct(
        private AppNewRepositoryInputValidator $validator = new AppNewRepositoryInputValidator,
    ) {}

    /**
     * @return array{
     *     repository: ?string,
     *     template_repository: ?string,
     *     new_repository: ?string,
     * }
     */
    public function prompt(): array
    {
        $source = (string) select(
            label: 'How should the app source be created?',
            options: [
                'new' => 'New repository from template',
                'clone' => 'Clone existing repository',
            ],
            default: 'new',
        );

        if ($source === 'clone') {
            return [
                'repository' => $this->promptForCloneRepository(),
                'template_repository' => null,
                'new_repository' => null,
            ];
        }

        return $this->completeTemplate(null, null);
    }

    /**
     * @return array{
     *     repository: null,
     *     template_repository: string,
     *     new_repository: string,
     * }
     */
    public function completeTemplate(?string $templateRepository, ?string $newRepository): array
    {
        $templateRepository ??= $this->promptForGithubRepository(
            'Template repository (GitHub owner/repo):',
        );
        $newRepository ??= $this->promptForGithubRepository(
            'New private repository (GitHub owner/repo):',
        );

        return [
            'repository' => null,
            'template_repository' => $templateRepository,
            'new_repository' => $newRepository,
        ];
    }

    private function promptForCloneRepository(): string
    {
        return trim(text(
            label: 'Repository URL (or GitHub owner/repo):',
            required: true,
            validate: fn (string $value): ?string => $this->validator->clone(trim($value)),
        ));
    }

    private function promptForGithubRepository(string $label): string
    {
        return trim(text(
            label: $label,
            required: true,
            validate: fn (string $value): ?string => $this->validator->github(trim($value)),
        ));
    }
}
