<?php

declare(strict_types=1);

use App\Tools\PhpCliTool;
use Orbit\Core\Php\PhpCliArtifactCatalog;
use Orbit\Core\Php\PhpCliVariant;
use Tests\TestCase;

uses(TestCase::class);

it('keeps production install scripts working under the matrix runtime contract', function (): void {
    $catalog = PhpCliArtifactCatalog::load();
    $tool = new PhpCliTool($catalog);

    expect($catalog->usesMatrixContract())
        ->toBeTrue()
        ->and($catalog->publicationStatus())
        ->toBe('published')
        ->and($catalog->matrixFullyPublished())
        ->toBeTrue();

    $script = $tool->installScript(['variant' => 'standard']);

    expect($script)
        ->toContain('https://s3.hardimpact.dev/orbit/runtimes/php-cli/sqlite-3.44.6')
        ->toContain('php-8.5.8-cli-standard-${OS}-${ARCH}.tar.gz')
        ->toContain('40a7d8144d5e90a7ce8d2cd12fc86758acef8dedc4f95025dee56d1b3a6ddf15')
        ->toContain('php-8.4.21-cli-standard-${OS}-${ARCH}.tar.gz')
        ->toContain('php-8.3.31-cli-standard-${OS}-${ARCH}.tar.gz')
        ->toContain('Stage and verify every minor')
        ->not->toContain('dl.static-php.dev/static-php-cli/bulk')
        // Matrix standard omits PCOV; coverage scripts assert the inverse.
        ->not->toContain('php-8.5.8-cli-coverage-');
});

it('build catalog is the published fleet-scoped 9-cell handoff matrix', function (): void {
    $build = PhpCliArtifactCatalog::loadBuild();

    expect($build->catalogRole())
        ->toBe('build')
        ->and($build->matrix())
        ->toHaveCount(9)
        ->and($build->platforms())
        ->toEqualCanonicalizing(['linux-x86_64', 'macos-aarch64'])
        ->and($build->matrixFullyPublished())
        ->toBeTrue()
        ->and($build->publicationStatus())
        ->toBe('published');
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
        'published_count' => 9,
        'total_count' => 9,
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
        ->and($runtime->usesMatrixContract())
        ->toBeTrue()
        ->and($build->catalogRole())
        ->toBe('build')
        ->and($runtime->sourcePath())
        ->toEndWith('artifact-catalog.json')
        ->and($build->sourcePath())
        ->toEndWith('artifact-catalog.build.json');

    // Production install uses the fully published matrix consumer catalog.
    $tool = new PhpCliTool($runtime);
    $script = $tool->installScript(['variant' => PhpCliVariant::Coverage->value]);
    expect($script)
        ->toContain('curl -fsSL')
        ->toContain('php-8.5.8-cli-coverage-${OS}-${ARCH}.tar.gz')
        ->toContain('99b4c794928963bb777432318493d08bdf5e57eab8ed19fc232057dd4e09846e')
        ->and($script)
        ->not->toContain('__unpublished__')
        ->not->toContain('dl.static-php.dev/static-php-cli/bulk');
});
