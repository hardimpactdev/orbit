<?php

declare(strict_types=1);

use App\Services\Tools\ToolCatalog;
use App\Tools\PhpCliTool;
use Orbit\Core\Php\PhpCliArtifactCatalog;
use Orbit\Core\Php\PhpCliVariant;
use Tests\TestCase;

uses(TestCase::class);

describe('PhpCliTool', function (): void {
    it('has the correct slug and category', function (): void {
        $tool = new PhpCliTool;

        expect($tool->slug())->toBe('php-cli')->and($tool->category())->toBe('runtime');
    });

    it('declares install, remove, update, and safe-adopt capabilities', function (): void {
        $tool = new PhpCliTool;

        expect($tool->capabilities())
            ->toContain('install')
            ->and($tool->capabilities())
            ->toContain('remove')
            ->and($tool->capabilities())
            ->toContain('update')
            ->and($tool->capabilities())
            ->toContain('safe-adopt');
    });

    it('removeScript only targets Orbit-owned php-cli install roots and managed symlinks', function (): void {
        $tool = new PhpCliTool;
        $script = $tool->removeScript();

        expect($script)
            ->toContain('# orbit remove php-cli')
            ->toContain(PhpCliTool::INSTALL_ROOT)
            ->toContain('/usr/local/bin/php')
            ->toContain('readlink')
            ->toContain('sudo rm -rf')
            ->not->toContain('apt-get')
            ->not->toContain('brew ')
            ->not->toContain('docker rmi');
    });

    it('supports Linux and macOS hosts', function (): void {
        $tool = new PhpCliTool;

        expect($tool->supportedOperatingSystems())->toBe(['linux', 'macos']);
    });

    it('resolves role-owned variants authoritatively', function (
        mixed $configVariant,
        ?string $role,
        string $expected,
    ): void {
        $tool = new PhpCliTool;
        $config = $configVariant === null ? [] : ['variant' => $configVariant];

        expect($tool->resolveVariant($config, $role)->value)->toBe($expected);
    })->with([
        'role wins over explicit coverage on app-prod' => ['coverage', 'app-prod', 'standard'],
        'role wins over explicit standard on app-dev' => ['standard', 'app-dev', 'coverage'],
        'explicit coverage without role' => ['coverage', null, 'coverage'],
        'explicit standard without role' => ['standard', null, 'standard'],
        'app-dev role default' => [null, 'app-dev', 'coverage'],
        'app-prod role default' => [null, 'app-prod', 'standard'],
        'default coverage' => [null, null, 'coverage'],
    ]);

    it('rejects invalid explicit variants without a role', function (): void {
        $tool = new PhpCliTool;

        expect(fn () => $tool->resolveVariant(['variant' => 'debug']))
            ->toThrow(InvalidArgumentException::class);
    });

    it('installScript under matrix uses variant-named Orbit artifacts for every minor', function (): void {
        $tool = new PhpCliTool;
        $coverage = $tool->installScript(['variant' => 'coverage']);
        $standard = $tool->installScript(['variant' => 'standard']);

        expect($coverage)
            ->toContain('https://s3.hardimpact.dev/orbit/runtimes/php-cli/sqlite-3.44.6')
            ->not->toContain('dl.static-php.dev/static-php-cli/bulk')->toContain('php-8.5.8-cli-coverage-')->toContain(
                'php-8.4.21-cli-coverage-',
            )->toContain('php-8.3.31-cli-coverage-')->toContain('extension_loaded("pcov")')->and($standard)->toContain(
                'php-8.5.8-cli-standard-',
            )
            ->not->toContain('php-8.5.8-cli-coverage-');
    });

    it('retries transient static PHP download failures', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript(['variant' => 'coverage']))
            ->toContain('curl -fsSL --retry 5 --retry-delay 2 --retry-all-errors')
            ->and($tool->updateScript(['variant' => 'standard']))
            ->toContain('curl -fsSL --retry 5 --retry-delay 2 --retry-all-errors');
    });

    it('installScript includes pinned patch versions for all supported minors', function (): void {
        $tool = new PhpCliTool;
        $script = $tool->installScript(['variant' => 'coverage']);

        expect($script)
            ->toContain('8.5.8')
            ->and($script)
            ->toContain('8.4.21')
            ->and($script)
            ->toContain('8.3.31');
    });

    it('stages and verifies every minor before replacing installed binaries', function (): void {
        $tool = new PhpCliTool;
        $script = $tool->installScript(['variant' => 'coverage']);

        expect($script)
            ->toContain('# Stage and verify every minor before replacing any installed runtime.')
            ->toContain('# Atomic install + relink after all staged runtimes passed verification.')
            ->toContain('shasum -a 256')
            ->toContain('php-8.5.next')
            ->toContain('mv -f')
            ->toContain('PHP_VERSION');
    });

    it('installScript installs binaries under /opt/orbit/php', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript(['variant' => 'coverage']))->toContain('/opt/orbit/php');
    });

    it('installScript detects OS and architecture with uname', function (): void {
        $tool = new PhpCliTool;
        $script = $tool->installScript(['variant' => 'coverage']);

        expect($script)
            ->toContain('uname -s')
            ->toContain('uname -m');
    });

    it('installScript creates the /usr/local/bin/php default symlink', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript(['variant' => 'coverage']))->toContain('/usr/local/bin/php');
    });

    it('installScript is idempotent and uses set -e', function (): void {
        $tool = new PhpCliTool;

        expect($tool->installScript(['variant' => 'coverage']))->toContain('set -euo pipefail');
    });

    it('installScript does not contain ondrej PPA or add-apt-repository', function (): void {
        $tool = new PhpCliTool;
        $script = $tool->installScript(['variant' => 'coverage']);

        expect($script)
            ->not->toContain('ppa:ondrej')->and($script)
            ->not->toContain('add-apt-repository');
    });

    it('updateScript reuses the same install contract', function (): void {
        $tool = new PhpCliTool;

        expect($tool->updateScript(['variant' => 'standard']))
            ->toContain('https://s3.hardimpact.dev/orbit/runtimes/php-cli/sqlite-3.44.6')
            ->not->toContain('--only-upgrade');
    });

    it('probeMetadata identifies the Orbit-managed php binary and runtime probe', function (): void {
        $tool = new PhpCliTool;
        $metadata = $tool->probeMetadata();

        expect($metadata['binary'])
            ->toBe('/opt/orbit/php/8.5/bin/php')
            ->and($metadata['version_command'])
            ->toBe('/opt/orbit/php/8.5/bin/php --version')
            ->and($metadata['probe'])
            ->toBe('php_cli_runtimes');
    });

    it('runtime probe script checks every supported minor', function (): void {
        $tool = new PhpCliTool;
        $script = $tool->runtimeProbeScript(PhpCliVariant::Coverage);

        expect($script)
            ->toContain('/opt/orbit/php/8.5/bin/php')
            ->toContain('/opt/orbit/php/8.4/bin/php')
            ->toContain('/opt/orbit/php/8.3/bin/php')
            ->toContain('extension_loaded("pcov")')
            ->toContain('function_exists("pcov\\\\start")');
    });

    it('is resolvable by slug from the tool catalog', function (): void {
        $catalog = app(ToolCatalog::class);

        expect($catalog->definition('php-cli'))->toBeInstanceOf(PhpCliTool::class);
    });

    it('separates the build matrix from the production runtime consumer', function (): void {
        $runtime = PhpCliArtifactCatalog::load();
        $build = PhpCliArtifactCatalog::loadBuild();

        expect($runtime->usesMatrixContract())
            ->toBeTrue()
            ->and($build->catalogRole())
            ->toBe('build')
            ->and($build->matrix())
            ->toHaveCount(9)
            ->and($build->matrixFullyPublished())
            ->toBeTrue()
            ->and($runtime->matrixFullyPublished())
            ->toBeTrue()
            ->and($runtime->sourcePath())
            ->not->toBe($build->sourcePath());
    });
});
