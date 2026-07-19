<?php

declare(strict_types=1);

namespace App\Services\Skill;

use App\Services\Updates\CheckoutPathResolver;
use Illuminate\Support\Facades\File;

final readonly class SkillInstallActions
{
    public function __construct(
        private CheckoutPathResolver $checkoutPaths,
        private SkillTargetResolver $targetResolver,
        private ?string $sourceOverride = null,
    ) {}

    public function install(SkillInstallRequest $request): SkillInstallResult|SkillInstallFailure
    {
        $resolution = $this->prepare($request);

        if ($resolution instanceof SkillInstallFailure) {
            return $resolution;
        }

        $source = $this->sourcePath();
        $target = $resolution->target;

        if ($this->targetExists($target) && ! $request->force) {
            return new SkillInstallFailure(
                code: 'validation_failed',
                message: 'Use --force to overwrite the existing skill target.',
                meta: [
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                    'target' => $target,
                ],
            );
        }

        if ($this->targetExists($target)) {
            $this->deleteTarget($target);
        }

        File::ensureDirectoryExists(dirname($target));

        if (! File::copyDirectory($source, $target)) {
            return new SkillInstallFailure(
                code: 'skill.install_failed',
                message: 'Could not copy the Orbit skill to the target path.',
                meta: [
                    'source' => $source,
                    'target' => $target,
                ],
            );
        }

        return new SkillInstallResult(provider: $resolution->provider, target: $target, source: $source);
    }

    public function prepare(SkillInstallRequest $request): SkillTargetResolution|SkillInstallFailure
    {
        $resolution = $this->targetResolver->resolve($request->provider, $request->path);

        if ($resolution instanceof SkillInstallFailure) {
            return $resolution;
        }

        $sourceValidation = $this->validateSource($this->sourcePath());

        return $sourceValidation ?? $resolution;
    }

    public function requiresReplacement(SkillTargetResolution $resolution): bool
    {
        return $this->targetExists($resolution->target);
    }

    private function validateSource(string $source): ?SkillInstallFailure
    {
        if (! is_dir($source) || ! is_file($source.'/SKILL.md')) {
            return new SkillInstallFailure(
                code: 'validation_failed',
                message: 'Repository Orbit skill source is missing or invalid.',
                meta: [
                    'field' => 'source',
                    'reason' => 'missing_source',
                    'source' => $source,
                ],
            );
        }

        return null;
    }

    private function sourcePath(): string
    {
        if ($this->sourceOverride !== null) {
            return rtrim(string: $this->sourceOverride, characters: '/');
        }

        return rtrim(string: $this->checkoutPaths->resolve(), characters: '/').'/.agents/skills/orbit';
    }

    private function targetExists(string $target): bool
    {
        return file_exists($target) || is_link($target);
    }

    private function deleteTarget(string $target): void
    {
        if (is_link($target) || is_file($target)) {
            File::delete($target);

            return;
        }

        File::deleteDirectory($target);
    }
}
