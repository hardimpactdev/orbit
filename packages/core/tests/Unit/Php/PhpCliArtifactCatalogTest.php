<?php

declare(strict_types=1);

use Orbit\Core\Php\PhpCliArtifactCatalog;
use Orbit\Core\Php\PhpCliVariant;

/**
 * @return array<string, mixed>
 */
function phpCliCatalogFixtureDocument(): array
{
    return json_decode(
        (string) file_get_contents(PhpCliArtifactCatalog::defaultBuildPath()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

function phpCliCatalogTempPath(array $document): string
{
    $path = sys_get_temp_dir().'/php-cli-catalog-'.bin2hex(random_bytes(4)).'.json';
    file_put_contents($path, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    return $path;
}

it('loads the runtime matrix catalog for production install after fleet cutover', function (): void {
    $catalog = PhpCliArtifactCatalog::load();

    expect($catalog->usesMatrixContract())
        ->toBeTrue()
        ->and($catalog->publicationStatus())
        ->toBe('published')
        ->and($catalog->matrixFullyPublished())
        ->toBeTrue()
        ->and($catalog->matrix())
        ->toHaveCount(9)
        ->and($catalog->artifactSha256('8.5.8', PhpCliVariant::Standard, 'linux-x86_64'))
        ->toBe('40a7d8144d5e90a7ce8d2cd12fc86758acef8dedc4f95025dee56d1b3a6ddf15')
        ->and($catalog->artifactSha256('8.5.8', PhpCliVariant::Coverage, 'macos-aarch64'))
        ->toBe('433e5771e93440a42d7dfe51b7b6471a579cdebe06ecea43d4033b44280e3475')
        ->and($catalog->extensionsFor(PhpCliVariant::Coverage))
        ->toContain('pcov')
        ->and($catalog->extensionsFor(PhpCliVariant::Standard))
        ->not
        ->toContain('pcov')
        ->and($catalog->installVerifiesPcov())
        ->toBeTrue()
        ->and($catalog->pcovVersion())
        ->toBe('1.0.12')
        ->and($catalog->pcovUrl())
        ->toBe('https://pecl.php.net/get/pcov-1.0.12.tgz')
        ->and($catalog->pcovArchiveSha256())
        ->toBe('23255c8c9335a9636ccb743f5302436a97a582a0bbde9869485be911bbc15da8');
});

it('loads the separate published fleet-scoped build matrix for handoff', function (): void {
    $catalog = PhpCliArtifactCatalog::loadBuild();

    expect($catalog->catalogRole())
        ->toBe('build')
        ->and($catalog->matrix())
        ->toHaveCount(PhpCliArtifactCatalog::MATRIX_CELL_COUNT)
        ->and(PhpCliArtifactCatalog::MATRIX_CELL_COUNT)
        ->toBe(9)
        ->and($catalog->platforms())
        ->toEqualCanonicalizing(['linux-x86_64', 'macos-aarch64'])
        ->and($catalog->matrixFullyPublished())
        ->toBeTrue()
        ->and($catalog->publicationStatus())
        ->toBe('published')
        ->and($catalog->artifactFileName('8.5.8', PhpCliVariant::Coverage, 'linux-x86_64'))
        ->toBe('php-8.5.8-cli-coverage-linux-x86_64.tar.gz')
        ->and($catalog->artifactSha256('8.5.8', PhpCliVariant::Coverage, 'linux-x86_64'))
        ->toBe('99b4c794928963bb777432318493d08bdf5e57eab8ed19fc232057dd4e09846e')
        ->and($catalog->pcovPin()['spc_source_name'])
        ->toBe('pcov')
        ->and($catalog->staticPhpCliExtJsonSha256())
        ->toBe('0fe7716d8cb199f34076c06a601b3ff9c8ffbce11d92a0bbd455d9d4f2d18d42')
        ->and($catalog->staticPhpCliSourceJsonSha256())
        ->toBe('573dc8b14c1e9f7bf4623054064c27a0c09ff6a67ce262cf53a73ad91104b4a0');

    $cellKeys = array_map(
        static fn (array $row): string => "{$row['patch']}/{$row['variant']}/{$row['platform']}",
        $catalog->matrix(),
    );
    expect($cellKeys)->toEqualCanonicalizing([
        '8.5.8/coverage/linux-x86_64',
        '8.5.8/coverage/macos-aarch64',
        '8.5.8/standard/linux-x86_64',
        '8.4.21/coverage/linux-x86_64',
        '8.4.21/coverage/macos-aarch64',
        '8.4.21/standard/linux-x86_64',
        '8.3.31/coverage/linux-x86_64',
        '8.3.31/coverage/macos-aarch64',
        '8.3.31/standard/linux-x86_64',
    ]);
});

it('fails closed for unpublished matrix checksums on an incomplete build catalog', function (): void {
    $document = phpCliCatalogFixtureDocument();
    $document['artifacts']['8.5.8']['coverage']['linux-x86_64'] = null;
    $path = phpCliCatalogTempPath($document);
    $catalog = PhpCliArtifactCatalog::load($path);

    expect(fn () => $catalog->publishedChecksumsFor(PhpCliVariant::Coverage, 'linux-x86_64'))
        ->toThrow(RuntimeException::class, 'unpublished');
});

it('rejects unknown catalog_role values', function (): void {
    $document = phpCliCatalogFixtureDocument();
    $document['catalog_role'] = 'staging';
    $path = phpCliCatalogTempPath($document);

    expect(fn () => PhpCliArtifactCatalog::load($path))
        ->toThrow(RuntimeException::class, "catalog_role must be 'runtime' or 'build'");
});

it('rejects unknown install_contract values on runtime catalogs', function (): void {
    $document = json_decode(
        (string) file_get_contents(PhpCliArtifactCatalog::defaultPath()),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $document['install_contract'] = 'preview';
    $path = phpCliCatalogTempPath($document);

    expect(fn () => PhpCliArtifactCatalog::load($path))
        ->toThrow(RuntimeException::class, "install_contract must be 'compatibility' or 'matrix'");
});

it('rejects catalogs that omit a fleet-scoped matrix platform slot', function (): void {
    $document = phpCliCatalogFixtureDocument();
    unset($document['artifacts']['8.5.8']['coverage']['macos-aarch64']);
    $path = phpCliCatalogTempPath($document);

    expect(fn () => PhpCliArtifactCatalog::load($path))
        ->toThrow(RuntimeException::class, "missing platform slot '8.5.8/coverage/macos-aarch64'");
});

it('rejects catalogs that reintroduce non-fleet matrix platforms', function (): void {
    $document = phpCliCatalogFixtureDocument();
    $document['artifacts']['8.5.8']['coverage']['linux-aarch64'] = null;
    $path = phpCliCatalogTempPath($document);

    expect(fn () => PhpCliArtifactCatalog::load($path))
        ->toThrow(RuntimeException::class, "non-fleet platform slot '8.5.8/coverage/linux-aarch64'");
});

it('rejects catalogs that omit a whole variant branch under a patch', function (): void {
    $document = phpCliCatalogFixtureDocument();
    unset($document['artifacts']['8.4.21']['standard']);
    $path = phpCliCatalogTempPath($document);

    expect(fn () => PhpCliArtifactCatalog::load($path))
        ->toThrow(RuntimeException::class, "missing variant slot '8.4.21/standard'");
});

it('rejects invalid non-null matrix slot digests', function (): void {
    $document = phpCliCatalogFixtureDocument();
    $document['artifacts']['8.5.8']['coverage']['linux-x86_64'] = 'not-a-sha';
    $path = phpCliCatalogTempPath($document);

    expect(fn () => PhpCliArtifactCatalog::load($path))
        ->toThrow(RuntimeException::class, 'must be null or a sha256 digest');
});
