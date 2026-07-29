<?php

declare(strict_types=1);

use App\Tools\PhpCliTool;
use Orbit\Core\Php\PhpCliArtifactCatalog;
use Orbit\Core\Php\PhpCliVariant;
use Tests\TestCase;

uses(TestCase::class);

it('keeps production install scripts working under the compatibility runtime contract', function (): void {
    $catalog = PhpCliArtifactCatalog::load();
    $tool = new PhpCliTool($catalog);

    expect($catalog->usesCompatibilityContract())
        ->toBeTrue()
        ->and($catalog->publicationStatus())
        ->toBe('compatibility');

    $script = $tool->installScript(['variant' => 'standard']);

    expect($script)
        ->toContain('https://s3.hardimpact.dev/orbit/runtimes/php-cli/sqlite-3.44.6')
        ->toContain('php-8.5.8-cli-${OS}-${ARCH}.tar.gz')
        ->toContain('305f0a3d80907c72a5d7e2ce4b78e120a2bc53848b809fb16fb7511c1b00b828')
        ->toContain('fbd88fc83c699e2f65030f314937ec05edba41209bd38c8baa11b86f224a9329')
        ->toContain('dl.static-php.dev/static-php-cli/bulk')
        ->toContain('php-8.4.21-cli-${OS}-${ARCH}.tar.gz')
        ->toContain('php-8.3.31-cli-${OS}-${ARCH}.tar.gz')
        ->toContain('Stage and verify every minor')
        ->not->toContain('php-8.5.8-cli-standard-')
        ->not->toContain('php-8.5.8-cli-coverage-')
        // Compatibility install does not require PCOV checks.
        ->not->toContain('PCOV_ENABLED=');
});

it('build catalog remains the unpublished 24-cell handoff matrix', function (): void {
    $build = PhpCliArtifactCatalog::loadBuild();

    expect($build->catalogRole())
        ->toBe('build')
        ->and($build->matrix())
        ->toHaveCount(24)
        ->and($build->matrixFullyPublished())
        ->toBeFalse()
        ->and($build->publicationStatus())
        ->toBeIn(['unpublished', 'partial']);
});

it('matrix install contract emits variant-named artifacts and PCOV checks', function (): void {
    $runtime = json_decode(
        (string) file_get_contents(repo_path(PhpCliArtifactCatalog::DEFAULT_CATALOG_RELATIVE_PATH)),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    $sha = str_repeat('11', 32);
    foreach ($runtime['matrix']['artifacts'] as $patch => $variants) {
        foreach ($variants as $variant => $platforms) {
            foreach (array_keys($platforms) as $platform) {
                $runtime['matrix']['artifacts'][$patch][$variant][$platform] = $sha;
            }
        }
    }
    $runtime['install_contract'] = 'matrix';
    $runtime['publication'] = [
        'status' => 'published',
        'published_count' => 24,
        'total_count' => 24,
    ];

    $path = sys_get_temp_dir().'/php-cli-runtime-matrix-'.bin2hex(random_bytes(3)).'.json';
    file_put_contents($path, json_encode($runtime, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    $catalog = PhpCliArtifactCatalog::load($path);
    $tool = new PhpCliTool($catalog);
    $script = $tool->installScript(['variant' => 'coverage']);

    expect($catalog->usesMatrixContract())
        ->toBeTrue()
        ->and($script)
        ->toContain('php-8.5.8-cli-coverage-${OS}-${ARCH}.tar.gz')
        ->toContain($sha)
        ->toContain('extension_loaded("pcov")')
        ->toContain('function_exists("pcov\\\\start")')
        ->not->toContain('dl.static-php.dev/static-php-cli/bulk');
});

it('does not treat the build catalog as a production consumer', function (): void {
    $runtime = PhpCliArtifactCatalog::load();
    $build = PhpCliArtifactCatalog::loadBuild();

    expect($runtime->sourcePath())
        ->not
        ->toBe($build->sourcePath())
        ->and($runtime->usesCompatibilityContract())
        ->toBeTrue()
        ->and($build->catalogRole())
        ->toBe('build');

    // Production install must not require unpublished matrix cells.
    $tool = new PhpCliTool($runtime);
    $script = $tool->installScript(['variant' => PhpCliVariant::Coverage->value]);
    expect($script)
        ->toContain('curl -fsSL')
        ->and($script)
        ->not->toContain('__unpublished__');
});
