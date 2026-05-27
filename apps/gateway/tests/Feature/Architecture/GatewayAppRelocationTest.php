<?php

declare(strict_types=1);

use App\Contracts\ProgressReporter;
use App\Services\Tools\ToolDefinitionRegistry;

function gatewayRelocationRepoRoot(): string
{
    $basePath = base_path();

    if (basename($basePath) === 'gateway' && basename(dirname($basePath)) === 'apps') {
        return dirname($basePath, 2);
    }

    return $basePath;
}

it('keeps the Laravel gateway app under apps gateway', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $gatewayRoot = "{$repoRoot}/apps/gateway";

    expect($gatewayRoot)->toBeDirectory()
        ->and("{$gatewayRoot}/artisan")->toBeFile()
        ->and("{$gatewayRoot}/app")->toBeDirectory()
        ->and("{$gatewayRoot}/bootstrap")->toBeDirectory()
        ->and("{$gatewayRoot}/config")->toBeDirectory()
        ->and("{$gatewayRoot}/database")->toBeDirectory()
        ->and("{$gatewayRoot}/routes")->toBeDirectory()
        ->and("{$gatewayRoot}/resources")->toBeDirectory()
        ->and("{$gatewayRoot}/tests")->toBeDirectory()
        ->and("{$repoRoot}/app")->not->toBeDirectory()
        ->and("{$repoRoot}/bootstrap")->not->toBeDirectory()
        ->and("{$repoRoot}/config")->not->toBeDirectory()
        ->and("{$repoRoot}/database")->not->toBeDirectory()
        ->and("{$repoRoot}/routes")->not->toBeDirectory()
        ->and("{$repoRoot}/resources")->not->toBeDirectory()
        ->and("{$repoRoot}/tests")->not->toBeDirectory();
});

it('keeps root autoload paths pointed at the relocated gateway app', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $composer = json_decode(
        (string) file_get_contents("{$repoRoot}/composer.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['autoload']['psr-4'])
        ->toMatchArray([
            'App\\' => 'apps/gateway/app/',
            'Database\\Factories\\' => 'apps/gateway/database/factories/',
            'Database\\Seeders\\' => 'apps/gateway/database/seeders/',
        ])
        ->and($composer['autoload-dev']['psr-4']['Tests\\'])->toBe('apps/gateway/tests/')
        ->and($composer['autoload-dev']['exclude-from-classmap'])->toContain('/apps/gateway/tests/')
        ->and($composer['autoload-dev']['files'])->toContain('apps/gateway/tests/Helpers/E2EEnvironment.php');
});

it('keeps root artisan as a compatibility shim for the relocated gateway', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $rootArtisan = file_get_contents("{$repoRoot}/artisan") ?: '';
    $gatewayArtisanPath = "{$repoRoot}/apps/gateway/artisan";
    $gatewayArtisan = file_exists($gatewayArtisanPath) ? (file_get_contents($gatewayArtisanPath) ?: '') : '';

    expect($rootArtisan)
        ->toContain('/apps/gateway/artisan')
        ->toContain('/bin/orbit-gateway-pest')
        ->and($gatewayArtisan)->toContain('/../../vendor/autoload.php');
});

it('registers the relocated gateway bootstrap for Laravel parallel tests', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $pestConfig = file_get_contents("{$repoRoot}/apps/gateway/tests/Pest.php") ?: '';

    expect($pestConfig)
        ->toContain('ParallelRunner::resolveApplicationUsing')
        ->toContain("__DIR__.'/../bootstrap/app.php'")
        ->toContain('Kernel::class');
});

it('registers gateway providers from the relocated bootstrap directory', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $gatewayBootstrap = file_get_contents("{$repoRoot}/apps/gateway/bootstrap/app.php") ?: '';

    expect($gatewayBootstrap)
        ->toContain('$gatewayRoot.\'/bootstrap/providers.php\'')
        ->and(app()->bound(ProgressReporter::class))->toBeTrue()
        ->and(app()->bound(ToolDefinitionRegistry::class))->toBeTrue();
});

it('points PHPStan at a bootstrap file for the relocated gateway app', function (): void {
    $repoRoot = gatewayRelocationRepoRoot();
    $phpstanConfig = file_get_contents("{$repoRoot}/phpstan.neon") ?: '';
    $phpstanBootstrap = file_get_contents("{$repoRoot}/apps/gateway/bootstrap/phpstan.php") ?: '';

    expect($phpstanConfig)
        ->toContain('apps/gateway/bootstrap/phpstan.php')
        ->and($phpstanBootstrap)->toContain("__DIR__.'/app.php'")
        ->and($phpstanBootstrap)->toContain('LARAVEL_VERSION');
});
