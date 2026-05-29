<?php

declare(strict_types=1);

use App\E2E\Support\E2EPaths;

it('boots as an external harness and resolves Orbit entry points', function (): void {
    $paths = E2EPaths::fromRepositoryRoot(repo_path());

    expect($paths->repoRoot())->toBe(repo_path())
        ->and($paths->cliLauncher())->toBe(repo_path('apps/cli/orbit'))
        ->and($paths->gatewayArtisan())->toBe(repo_path('apps/gateway/artisan'))
        ->and($paths->e2eAppRoot())->toBe(repo_path('apps/e2e'))
        ->and($paths->cliLauncher())->toBeFile()
        ->and($paths->gatewayArtisan())->toBeFile();
});

it('documents the apps/e2e internal support boundary', function (): void {
    $readme = file_get_contents(repo_path('apps/e2e/README.md')) ?: '';
    $normalizedReadme = preg_replace('/\s+/', ' ', $readme) ?? '';

    expect($normalizedReadme)
        ->toContain('apps/e2e internal support')
        ->toContain('CLI, gateway HTTP API, SSH, Docker, Incus, and process boundaries')
        ->toContain('must not import gateway services, models, controllers, jobs, or internal commands')
        ->and(repo_path('packages/e2e-support'))->not->toBeDirectory();
});
