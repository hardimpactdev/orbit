<?php

declare(strict_types=1);

namespace App\Services\Skill;

readonly class SkillTargetResolver
{
    public function __construct(
        private ?string $homeOverride = null,
    ) {}

    /**
     * @return array{provider: ?string, target: string}|SkillInstallFailure
     */
    public function resolve(?string $providerSlug, ?string $path): array|SkillInstallFailure
    {
        if ($providerSlug === null && $path === null) {
            return new SkillInstallFailure(
                code: 'validation_failed',
                message: 'Provide a provider slug or an explicit target path.',
                meta: [
                    'fields' => ['provider', 'path'],
                    'reason' => 'missing_target',
                ],
            );
        }

        if ($providerSlug !== null && $path !== null) {
            return $this->resolveProviderPathPair($providerSlug, $path);
        }

        if ($path !== null && $providerSlug === null) {
            return ['provider' => null, 'target' => $path];
        }

        $provider = SkillProvider::tryFromSlug($providerSlug);

        if ($provider === null) {
            return ['provider' => null, 'target' => $providerSlug];
        }

        $home = $this->homeDirectory();

        if ($home === null) {
            return new SkillInstallFailure(
                code: 'validation_failed',
                message: 'HOME environment variable is required for provider default installs.',
                meta: [
                    'field' => 'home',
                    'reason' => 'missing_home',
                    'provider' => $provider->value,
                ],
            );
        }

        return [
            'provider' => $provider->value,
            'target' => $provider->defaultTargetPath($home),
        ];
    }

    /**
     * @return array{provider: ?string, target: string}|SkillInstallFailure
     */
    private function resolveProviderPathPair(
        string $providerSlug,
        string $path,
    ): array|SkillInstallFailure {
        $provider = SkillProvider::tryFromSlug($providerSlug);

        if ($provider === null) {
            return new SkillInstallFailure(
                code: 'validation_failed',
                message: 'Do not provide a second path when the first argument is an explicit target path.',
                meta: [
                    'field' => 'path',
                    'reason' => 'unexpected_path',
                    'target' => $providerSlug,
                    'value' => $path,
                ],
            );
        }

        return ['provider' => $provider->value, 'target' => $path];
    }

    private function homeDirectory(): ?string
    {
        if ($this->homeOverride !== null) {
            return rtrim(string: $this->homeOverride, characters: '/');
        }

        $home = getenv('HOME');

        if (! is_string($home) || $home === '') {
            return null;
        }

        return rtrim(string: $home, characters: '/');
    }
}
