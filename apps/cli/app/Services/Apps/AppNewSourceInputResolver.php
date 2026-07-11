<?php

declare(strict_types=1);

namespace App\Services\Apps;

final readonly class AppNewSourceInputResolver
{
    public function __construct(
        private AppNewRepositoryInputValidator $validator = new AppNewRepositoryInputValidator,
        private AppNewSourcePrompter $prompter = new AppNewSourcePrompter,
    ) {}

    /**
     * @return array{
     *     repository: ?string,
     *     template_repository: ?string,
     *     new_repository: ?string,
     * }
     */
    public function resolveInteractive(
        ?string $repository,
        ?string $templateRepository,
        ?string $newRepository,
    ): array {
        return $this->resolve($repository, $templateRepository, $newRepository, $this->prompter);
    }

    /**
     * @return array{
     *     repository: ?string,
     *     template_repository: ?string,
     *     new_repository: ?string,
     * }
     */
    public function resolveNonInteractive(
        ?string $repository,
        ?string $templateRepository,
        ?string $newRepository,
    ): array {
        return $this->resolve($repository, $templateRepository, $newRepository, null);
    }

    /**
     * @return array{
     *     repository: ?string,
     *     template_repository: ?string,
     *     new_repository: ?string,
     * }
     */
    private function resolve(
        ?string $repository,
        ?string $templateRepository,
        ?string $newRepository,
        ?AppNewSourcePrompter $prompter,
    ): array {
        if ($repository !== null && ($templateRepository !== null || $newRepository !== null)) {
            $this->throwSourceSelectionFailure();
        }

        if ($repository !== null) {
            $this->validator->cloneOrFail($repository);

            return $this->cloneSource($repository);
        }

        if ($templateRepository !== null || $newRepository !== null) {
            return $this->resolveTemplateSource($templateRepository, $newRepository, $prompter);
        }

        if ($prompter === null) {
            $this->throwSourceSelectionFailure();
        }

        return $prompter->prompt();
    }

    /**
     * @return array{
     *     repository: null,
     *     template_repository: string,
     *     new_repository: string,
     * }
     */
    private function resolveTemplateSource(
        ?string $templateRepository,
        ?string $newRepository,
        ?AppNewSourcePrompter $prompter,
    ): array {
        if ($templateRepository !== null) {
            $this->validator->githubOrFail('template-repo', $templateRepository);
        }

        if ($newRepository !== null) {
            $this->validator->githubOrFail('new-repo', $newRepository);
        }

        if ($templateRepository === null || $newRepository === null) {
            if ($prompter === null) {
                $this->throwSourceSelectionFailure();
            }

            return $prompter->completeTemplate($templateRepository, $newRepository);
        }

        return [
            'repository' => null,
            'template_repository' => $templateRepository,
            'new_repository' => $newRepository,
        ];
    }

    /**
     * @return array{
     *     repository: string,
     *     template_repository: null,
     *     new_repository: null,
     * }
     */
    private function cloneSource(string $repository): array
    {
        return [
            'repository' => $repository,
            'template_repository' => null,
            'new_repository' => null,
        ];
    }

    private function throwSourceSelectionFailure(): never
    {
        throw new AppNewSourceValidationFailed(
            'source',
            'Supply --repo, or supply both --template-repo and --new-repo.',
            ['fields' => ['repo', 'template-repo', 'new-repo']],
        );
    }
}
