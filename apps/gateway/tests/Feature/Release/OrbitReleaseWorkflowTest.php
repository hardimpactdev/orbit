<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

function prepareReleaseWorkflowPackage(string $package, string $version, string $output): void
{
    $process = new Process([
        PHP_BINARY,
        repo_path('bin/orbit-prepare-release-package'),
        "--package={$package}",
        "--version={$version}",
        "--output={$output}",
    ], repo_path());
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput());
}

it('promotes prebuilt cli artifacts gateway image and release manifest on GitHub releases', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($workflow)
        ->toContain('name: Orbit Release')
        ->toContain('types: [published]')
        ->toContain('contents: write')
        ->toContain('bin/orbit-version')
        ->toContain('Release tag')
        ->toContain('does not match VERSION')
        ->toContain('Download prebuilt release assets')
        ->toContain('gh release download "$TAG"')
        ->toContain('Verify promoted release manifest')
        ->toContain('source')
        ->toContain('github-release')
        ->toContain('hash_file')
        ->toContain('Verify promoted gateway image is pullable')
        ->toContain('Verify promoted gateway image bakes the release version')
        ->toContain('bin/orbit-prepare-release-package --package="$package"')
        ->toContain('publish_split core hardimpactdev/orbit-core')
        ->toContain('publish_split cli hardimpactdev/orbit-cli')
        ->toContain('publish_split gateway hardimpactdev/orbit-gateway')
        ->toContain('hardimpactdev/orbit-core')
        ->toContain('hardimpactdev/orbit-cli')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('rm -rf "$output/node_modules"')
        ->toContain('ORBIT_RELEASE_TOKEN')
        ->toContain('PACKAGIST_USERNAME')
        ->toContain('PACKAGIST_TOKEN')
        ->toContain('ghcr.io')
        ->toContain('hardimpactdev/orbit-gateway')
        ->toContain('orbit-release-manifest.json')
        ->toContain('orbit-linux-x64')
        ->toContain('orbit-macos-arm64')
        // TypeScript SDK is independently versioned and published from its
        // package repository OIDC workflow, not monorepo orbit-release.yml.
        ->not->toContain('prepare_and_verify_sdk_typescript')
        ->not->toContain('publish_sdk_typescript_npm')
        ->not->toContain('publish_sdk_typescript_split')
        ->not->toContain('npm publish --access public --provenance')
        ->not->toContain('NPM_TOKEN')
        ->not->toContain('NODE_AUTH_TOKEN')
        ->not->toContain('actions/setup-node@v6')
        ->not->toContain('actions/setup-node@v4')
        ->not->toContain('@orbit/sdk-typescript')
        ->not->toContain('docker buildx build')
        ->not->toContain('--push')
        ->not->toContain('--metadata-file')
        ->not->toContain('containerimage.digest')
        ->not->toContain('bin/orbit-build-cli-binary mac arm')
        ->not->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain('bin/orbit-release-manifest')
        ->not->toContain('gh release upload')
        ->not->toContain('--clobber')
        ->not->toContain('cp apps/cli/builds/dist/linux/linux-x64 orbit-linux-x64')
        ->not->toContain('cp apps/cli/builds/dist/mac/mac-arm orbit-macos-arm64')
        ->not->toContain("sed -n \"s/.*'version' =>")
        ->not->toContain('#orbit-linux-x64')
        ->not->toContain('#orbit-macos-arm64')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64')
        ->not->toContain('orbit'.'-runtime');
});

it('keeps the TypeScript SDK independently versioned with Craft-style package-repo OIDC publish', function (): void {
    $rootVersion = trim((string) file_get_contents(repo_path('VERSION')));
    /** @var array<string, mixed> $sourcePackage */
    $sourcePackage = json_decode(
        (string) file_get_contents(repo_path('packages/sdk-typescript/package.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $packageVersion = is_string($sourcePackage['version'] ?? null) ? $sourcePackage['version'] : '';

    expect($packageVersion)
        ->toBe('0.3.0')
        ->and($packageVersion)
        ->not
        ->toBe($rootVersion)
        ->and($sourcePackage['private'] ?? false)
        ->toBeTrue();

    $publishWorkflow = (string) file_get_contents(
        repo_path('packages/sdk-typescript/.github/workflows/publish.yml'),
    );

    expect($publishWorkflow)
        ->toContain('name: Publish package to npm')
        ->toContain('types: [published]')
        ->toContain('workflow_dispatch:')
        ->toContain('contents: read')
        ->toContain('id-token: write')
        ->toContain('actions/setup-node@v6')
        ->toContain('node-version: "24"')
        ->toContain('package-manager-cache: false')
        ->toContain('registry-url: "https://registry.npmjs.org"')
        ->toContain('npm ci')
        ->toContain('npm test')
        ->toContain('npm run build')
        ->toContain('npm version "${GITHUB_REF_NAME#v}" --no-git-tag-version --allow-same-version')
        ->toContain('npm publish --provenance --access public')
        // Steady-state release path is token-free OIDC.
        ->toContain('github.event_name == \'release\'')
        ->toContain('Publish release via OIDC Trusted Publisher')
        // One-time bootstrap is fail-closed and split-repo secret only.
        ->toContain('github.event_name == \'workflow_dispatch\'')
        ->toContain('Guard one-time bootstrap publish')
        ->toContain('Bootstrap refuses version')
        ->toContain('scripts/npm-bootstrap-registry-absent.sh')
        ->toContain('only exact 0.1.0 is allowed')
        ->toContain('confirmed npm E404 absence')
        ->toContain('secrets.NPM_BOOTSTRAP_TOKEN')
        ->toContain('Publish bootstrap with short-lived split-repo credential')
        // Monorepo durable secret name must not appear (bootstrap uses NPM_BOOTSTRAP_TOKEN only).
        ->not->toContain('secrets.NPM_TOKEN')
        ->not->toContain('${{ secrets.NPM_TOKEN }}');

    $registryGuard = (string) file_get_contents(
        repo_path('packages/sdk-typescript/scripts/npm-bootstrap-registry-absent.sh'),
    );

    expect(repo_path('packages/sdk-typescript/scripts/npm-bootstrap-registry-absent.sh'))
        ->toBeFile()
        ->and(is_executable(repo_path('packages/sdk-typescript/scripts/npm-bootstrap-registry-absent.sh')))
        ->toBeTrue()
        ->and($registryGuard)
        ->toContain('already exists on the registry; bootstrap is one-time only')
        ->toContain('non-E404 error; bootstrap refuses to proceed')
        ->toContain('code E404');

    // Release publish step must not inject a long-lived monorepo token.
    expect($publishWorkflow)
        ->toMatch(
            '/Publish release via OIDC Trusted Publisher[\s\S]*?if: github\.event_name == \'release\'[\s\S]*?run: npm publish --provenance --access public/',
        );

    $rootWorkflow = (string) file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($rootWorkflow)
        ->not->toContain('publish_sdk_typescript_npm')
        ->not->toContain('npm publish --access public --provenance')
        ->not->toContain('NPM_BOOTSTRAP_TOKEN')
        ->not->toContain('NPM_TOKEN');
});

it('executes npm bootstrap registry absence checks with fail-closed E404 semantics', function (): void {
    $script = repo_path('packages/sdk-typescript/scripts/npm-bootstrap-registry-absent.sh');
    $mockRoot = sys_get_temp_dir().'/orbit-npm-bootstrap-mock-'.bin2hex(random_bytes(6));
    $mockBin = $mockRoot.'/bin';

    mkdir($mockBin, 0755, true);

    $npmStub = <<<'BASH'
        #!/usr/bin/env bash
        set -euo pipefail

        mode="${ORBIT_NPM_VIEW_MODE:-}"
        spec="${2:-}"

        case "$mode" in
          e404)
            echo "npm error code E404" >&2
            echo "npm error 404 Not Found - GET https://registry.npmjs.org/${spec} - Not found" >&2
            exit 1
            ;;
          exists)
            echo "0.1.0"
            exit 0
            ;;
          network)
            echo "npm error code ENOTFOUND" >&2
            echo "npm error network request failed" >&2
            exit 1
            ;;
          auth)
            echo "npm error code E401" >&2
            echo "npm error 401 Unauthorized" >&2
            exit 1
            ;;
          *)
            echo "unknown ORBIT_NPM_VIEW_MODE [$mode]" >&2
            exit 2
            ;;
        esac
        BASH;

    file_put_contents($mockBin.'/npm', $npmStub);
    chmod($mockBin.'/npm', 0755);

    $run = static function (string $mode, string $spec) use ($script, $mockBin): Process {
        $process = new Process(
            ['bash', $script, $spec],
            null,
            [
                'PATH' => $mockBin.PATH_SEPARATOR.getenv('PATH'),
                'ORBIT_NPM_VIEW_MODE' => $mode,
            ],
        );
        $process->run();

        return $process;
    };

    try {
        $absent = $run('e404', '@hardimpactdev/orbit-sdk-typescript');
        expect($absent->getExitCode())
            ->toBe(0, $absent->getErrorOutput().$absent->getOutput());

        $absentVersion = $run('e404', '@hardimpactdev/orbit-sdk-typescript@0.1.0');
        expect($absentVersion->getExitCode())
            ->toBe(0, $absentVersion->getErrorOutput().$absentVersion->getOutput());

        $exists = $run('exists', '@hardimpactdev/orbit-sdk-typescript');
        expect($exists->getExitCode())
            ->not
            ->toBe(0)
            ->and($exists->getErrorOutput())
            ->toContain('already exists on the registry; bootstrap is one-time only');

        $network = $run('network', '@hardimpactdev/orbit-sdk-typescript');
        expect($network->getExitCode())
            ->not
            ->toBe(0)
            ->and($network->getErrorOutput())
            ->toContain('non-E404 error; bootstrap refuses to proceed')
            ->toContain('ENOTFOUND');

        $auth = $run('auth', '@hardimpactdev/orbit-sdk-typescript@0.1.0');
        expect($auth->getExitCode())
            ->not
            ->toBe(0)
            ->and($auth->getErrorOutput())
            ->toContain('non-E404 error; bootstrap refuses to proceed')
            ->toContain('E401');
    } finally {
        File::deleteDirectory($mockRoot);
    }
});

it('prepares a local durable sdk-typescript package with independent package.json version', function (): void {
    $rootVersion = trim((string) file_get_contents(repo_path('VERSION')));
    /** @var array<string, mixed> $sourcePackage */
    $sourcePackage = json_decode(
        (string) file_get_contents(repo_path('packages/sdk-typescript/package.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $packageVersion = is_string($sourcePackage['version'] ?? null) ? $sourcePackage['version'] : '';
    $output = sys_get_temp_dir().'/orbit-sdk-typescript-prepare-'.uniqid('', true);

    prepareReleaseWorkflowPackage('sdk-typescript', $packageVersion, $output);

    expect($output.'/package.json')
        ->toBeFile()
        ->and($output.'/README.md')
        ->toBeFile()
        ->and($output.'/openapi/public-gateway-openapi.json')
        ->toBeFile()
        ->and($output.'/package-lock.json')
        ->toBeFile()
        ->and($output.'/.github/workflows/publish.yml')
        ->toBeFile()
        ->and(is_dir($output.'/node_modules'))
        ->toBeFalse();

    /** @var array<string, mixed> $packageJson */
    $packageJson = json_decode((string) file_get_contents($output.'/package.json'), true, flags: JSON_THROW_ON_ERROR);

    expect($packageJson['name'] ?? null)
        ->toBe('@hardimpactdev/orbit-sdk-typescript')
        ->and($packageJson['version'] ?? null)
        ->toBe($packageVersion)
        ->and($packageJson['version'] ?? null)
        ->not->toBe($rootVersion)->and($packageJson['private'] ?? true)->toBeFalse()->and(
            $packageJson['license'] ?? null,
        )->toBe('MIT')->and($packageJson['publishConfig'] ?? null)->toMatchArray([
            'access' => 'public',
            'registry' => 'https://registry.npmjs.org/',
        ])->and(data_get($packageJson, 'repository.url'))->toBe(
            'https://github.com/hardimpactdev/orbit-sdk-typescript.git',
        )->and(data_get($packageJson, 'repository.directory'))->toBeNull()->and($packageJson['homepage'] ?? null)->toBe(
            'https://github.com/hardimpactdev/orbit-sdk-typescript',
        )->and($packageJson['files'] ?? null)->toBe([
            'dist',
            'openapi/public-gateway-openapi.json',
            'README.md',
        ])->and($packageJson['scripts'] ?? null)->toBe([
            'build' => 'tsc -p tsconfig.build.json',
            'typecheck' => 'tsc --noEmit',
            'test' => 'npm run typecheck',
        ])->and(json_encode($packageJson))
        ->not->toContain('@orbit/sdk-typescript');

    $sourcePackageJson = (string) file_get_contents(repo_path('packages/sdk-typescript/package.json'));
    $helper = (string) file_get_contents(repo_path('bin/orbit-prepare-release-package'));

    expect($sourcePackageJson)
        ->toContain('"name": "@hardimpactdev/orbit-sdk-typescript"')
        ->toContain('"private": true')
        ->not->toContain('@orbit/sdk-typescript')->and($helper)->toContain(
            "'sdk-typescript' => 'packages/sdk-typescript'",
        )->toContain('@hardimpactdev/orbit-sdk-typescript')->toContain('rewrite_npm_package_json')->toContain(
            'rewrite_npm_package_lock',
        )->toContain('read_npm_package_version')
        ->not->toContain('@orbit/sdk-typescript');

    File::deleteDirectory($output);
});

it('stamps prepared sdk-typescript package-lock identity to the package version not root VERSION', function (): void {
    $rootVersion = trim((string) file_get_contents(repo_path('VERSION')));
    /** @var array<string, mixed> $sourcePackage */
    $sourcePackage = json_decode(
        (string) file_get_contents(repo_path('packages/sdk-typescript/package.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $packageVersion = is_string($sourcePackage['version'] ?? null) ? $sourcePackage['version'] : '';
    $output = sys_get_temp_dir().'/orbit-sdk-typescript-lock-'.uniqid('', true);

    prepareReleaseWorkflowPackage('sdk-typescript', $packageVersion, $output);

    /** @var array<string, mixed> $packageLock */
    $packageLock = json_decode(
        (string) file_get_contents($output.'/package-lock.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
    $lockRoot = is_array($packageLock['packages'][''] ?? null) ? $packageLock['packages'][''] : [];

    expect($packageLock['name'] ?? null)
        ->toBe('@hardimpactdev/orbit-sdk-typescript')
        ->and($packageLock['version'] ?? null)
        ->toBe($packageVersion)
        ->and($packageLock['version'] ?? null)
        ->not
        ->toBe($rootVersion)
        ->and($lockRoot['name'] ?? null)
        ->toBe('@hardimpactdev/orbit-sdk-typescript')
        ->and($lockRoot['version'] ?? null)
        ->toBe($packageVersion);

    File::deleteDirectory($output);
});

it('rejects preparing sdk-typescript with a version that is not package.json', function (): void {
    $output = sys_get_temp_dir().'/orbit-sdk-typescript-bad-version-'.uniqid('', true);
    $process = new Process([
        PHP_BINARY,
        repo_path('bin/orbit-prepare-release-package'),
        '--package=sdk-typescript',
        '--version=9.9.9',
        "--output={$output}",
    ], repo_path());
    $process->run();

    expect($process->getExitCode())
        ->not
        ->toBe(0)
        ->and($process->getErrorOutput())
        ->toContain('must match packages/sdk-typescript/package.json version');

    if (is_dir($output)) {
        File::deleteDirectory($output);
    }
});

it('builds cli binary workflows through the shared no-dev compressed phar helper', function (): void {
    $binaryWorkflow = file_get_contents(repo_path('.github/workflows/orbit-cli-binary.yml'));
    $releaseWorkflow = file_get_contents(repo_path('.github/workflows/orbit-release.yml'));

    expect($binaryWorkflow)
        ->toContain('zlib')
        ->toContain('bin/orbit-version')
        ->toContain('bin/orbit-build-cli-binary mac arm')
        ->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain("sed -n \"s/.*'version' =>")
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');

    expect($releaseWorkflow)
        ->toContain('zlib')
        ->toContain('bin/orbit-version')
        ->not->toContain('bin/orbit-build-cli-binary mac arm')
        ->not->toContain('bin/orbit-build-cli-binary linux x64')
        ->not->toContain("sed -n \"s/.*'version' =>")
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');
});

it('uses the root VERSION file as the single release version source', function (): void {
    $version = trim((string) file_get_contents(repo_path('VERSION')));
    $cliConfig = file_get_contents(repo_path('apps/cli/config/app.php'));
    $gatewayConfig = file_get_contents(repo_path('apps/gateway/config/app.php'));
    $gatewayWorkflow = file_get_contents(repo_path('.github/workflows/orbit-gateway-image.yml'));
    $binaryWorkflow = file_get_contents(repo_path('.github/workflows/orbit-cli-binary.yml'));

    expect($version)
        ->toMatch('/^\d+\.\d+\.\d+$/')
        ->and(config('app.version'))
        ->toBe($version)
        ->and(repo_path('bin/orbit-version'))
        ->toBeFile()
        ->and($cliConfig)
        ->toContain('VERSION')
        ->and($gatewayConfig)
        ->toContain('VERSION')
        ->and($cliConfig)
        ->not->toContain("'version' => '")->and($gatewayConfig)
        ->not->toContain("'version' => '")->and($gatewayWorkflow)->toContain('bin/orbit-version')->and(
            $binaryWorkflow,
        )->toContain('bin/orbit-version');
});

it('builds local e2e cli binary artifacts through the shared helper', function (): void {
    $composer = json_decode((string) file_get_contents(repo_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);

    $binaryScript = implode("\n", $composer['scripts']['test:e2e:binary']);
    $linuxBinaryScript = implode("\n", $composer['scripts']['test:e2e:binary:linux']);
    $dockerAcceptanceScript = implode("\n", $composer['scripts']['test:e2e:docker:binary-acceptance']);
    $combinedScripts = implode("\n", [$binaryScript, $linuxBinaryScript, $dockerAcceptanceScript]);

    expect($binaryScript)
        ->toContain('bin/orbit-build-cli-binary mac arm "$(bin/orbit-version)"')
        ->toContain('ORBIT_E2E_BINARY_PATH=$(pwd)/apps/cli/builds/dist/mac/mac-arm');

    expect($linuxBinaryScript)
        ->toContain('bin/orbit-build-cli-binary linux x64 "$(bin/orbit-version)"');

    expect($dockerAcceptanceScript)
        ->toContain('bin/orbit-build-cli-binary linux x64 "$(bin/orbit-version)"')
        ->toContain('ORBIT_E2E_BINARY_PATH_LINUX=$(pwd)/apps/cli/builds/dist/linux/linux-x64');

    expect($combinedScripts)
        ->not->toContain('rm -rf apps/cli/vendor/hardimpactdev/orbit-core')
        ->not->toContain('php orbit app:build orbit.phar')
        ->not->toContain('vendor/bin/phpacker build mac arm')
        ->not->toContain('vendor/bin/phpacker build linux x64');
});

it('documents the compressed phar runtime extension contract', function (): void {
    $documents = [
        file_get_contents(repo_path('apps/docs/content/tech-stack.md')),
        file_get_contents(repo_path('apps/docs/content/domains/1_node/README.md')),
        file_get_contents(repo_path('apps/docs/content/domains/1_node/node-concepts.md')),
    ];

    foreach ($documents as $document) {
        expect($document)
            ->toContain('pdo_sqlite')
            ->toContain('phar')
            ->toContain('zlib');
    }
});

it('keeps gateway image build hygiene covered by dockerignore in the gateway image workflow', function (): void {
    $workflow = file_get_contents(repo_path('.github/workflows/orbit-gateway-image.yml'));
    $dockerignore = file_get_contents(repo_path('docker/orbit-gateway/Dockerfile.dockerignore'));

    expect($workflow)
        ->toContain('docker/orbit-gateway/Dockerfile')
        ->toContain('docker/orbit-gateway/Dockerfile.dockerignore');

    expect($dockerignore)
        ->toContain('**/.env')
        ->toContain('**/vendor')
        ->toContain('storage/logs')
        ->toContain('storage/*.sqlite')
        ->toContain('node_modules')
        ->toContain('.git');
});

it('prepares the gateway release split package manifest with the exact monorepo version', function (): void {
    $root = sys_get_temp_dir().'/orbit-release-package-'.bin2hex(random_bytes(6));

    try {
        prepareReleaseWorkflowPackage('gateway', '1.2.3', "{$root}/gateway");

        $gateway = json_decode(
            (string) file_get_contents("{$root}/gateway/composer.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($gateway['name'])
            ->toBe('hardimpactdev/orbit-gateway')
            ->and($gateway['version'])
            ->toBe('1.2.3')
            ->and($gateway['require']['hardimpactdev/orbit-core'])
            ->toBe('1.2.3')
            ->and($gateway['require']['hardimpactdev/orbit-sdk-laravel'])
            ->toBe('1.2.3')
            ->and($gateway)
            ->not->toHaveKey('repositories')->and("{$root}/gateway/composer.lock")
            ->not->toBeFile()->and(is_executable("{$root}/gateway/artisan"))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
});

it('does not descend into skipped gateway runtime directories while packaging', function (): void {
    $root = sys_get_temp_dir().'/orbit-release-package-'.bin2hex(random_bytes(6));
    $skippedDirectory = repo_path(
        'apps/gateway/storage/framework/testing/release-package-unreadable-'.bin2hex(random_bytes(6)),
    );

    try {
        mkdir($skippedDirectory, 0000, true);

        prepareReleaseWorkflowPackage('gateway', '1.2.3', "{$root}/gateway");

        expect("{$root}/gateway/storage/framework/testing")->not->toBeDirectory();
    } finally {
        if (is_dir($skippedDirectory)) {
            chmod($skippedDirectory, 0700);
            File::deleteDirectory($skippedDirectory);
        }

        File::deleteDirectory($root);
    }
});

it('prepares every release split package manifest with the exact monorepo version', function (): void {
    $root = sys_get_temp_dir().'/orbit-release-package-'.bin2hex(random_bytes(6));

    try {
        foreach (['core', 'sdk', 'cli', 'gateway'] as $package) {
            prepareReleaseWorkflowPackage($package, '1.2.3', "{$root}/{$package}");
        }

        $core = json_decode((string) file_get_contents("{$root}/core/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $sdk = json_decode((string) file_get_contents("{$root}/sdk/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $cli = json_decode((string) file_get_contents("{$root}/cli/composer.json"), true, flags: JSON_THROW_ON_ERROR);
        $gateway = json_decode(
            (string) file_get_contents("{$root}/gateway/composer.json"),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($core['name'])
            ->toBe('hardimpactdev/orbit-core')
            ->and($core['version'])
            ->toBe('1.2.3')
            ->and("{$root}/core/composer.lock")
            ->not->toBeFile()->and($sdk['name'])->toBe('hardimpactdev/orbit-sdk-laravel')->and($sdk['version'])->toBe(
                '1.2.3',
            )->and("{$root}/sdk/composer.lock")
            ->not->toBeFile()->and($cli['name'])->toBe('hardimpactdev/orbit-cli')->and($cli['version'])->toBe(
                '1.2.3',
            )->and($cli['require']['hardimpactdev/orbit-core'])->toBe('1.2.3')->and(
                $cli['require']['hardimpactdev/orbit-sdk-laravel'],
            )->toBe('1.2.3')->and($cli)
            ->not->toHaveKey('repositories')->and("{$root}/cli/composer.lock")
            ->not->toBeFile()->and(is_executable("{$root}/cli/orbit"))->toBeTrue()->and($gateway['name'])->toBe(
                'hardimpactdev/orbit-gateway',
            )->and($gateway['version'])->toBe('1.2.3')->and($gateway['require']['hardimpactdev/orbit-core'])->toBe(
                '1.2.3',
            )->and($gateway['require']['hardimpactdev/orbit-sdk-laravel'])->toBe('1.2.3')->and($gateway)
            ->not->toHaveKey('repositories')->and("{$root}/gateway/composer.lock")
            ->not->toBeFile()->and(is_executable("{$root}/gateway/artisan"))->toBeTrue();
    } finally {
        File::deleteDirectory($root);
    }
})->group('slow');
